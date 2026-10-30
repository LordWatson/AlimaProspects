<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Actions;

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Outreach\Data\DraftMessageData;
use App\Modules\Outreach\Data\DraftResult;
use App\Modules\Outreach\Data\MessageDraft;
use App\Modules\Outreach\Exceptions\OutreachBlockedException;
use App\Modules\Outreach\Exceptions\OutreachException;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachTemplate;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Outreach\Services\OutreachPayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes one draft for one lead (Phase 11) and stores it as an {@see OutreachMessage}.
 *
 * The order of operations is the whole design:
 *
 * 1. **The guard runs first**, before a single token is spent. A lead carrying a prior-consent
 *    restriction is refused the email channel at *draft* time, not just at send time
 *    (docs/modules/outreach.md, point 2) — which also means the team is never charged for a draft
 *    they were never allowed to use.
 * 2. **The model call runs outside any transaction** (CLAUDE.md external-call rule).
 * 3. **The write is one short transaction**, and the draft is a new row rather than an update: a
 *    member who re-drafts is asking for another option, and the one they liked three attempts ago
 *    should still be there.
 *
 * Must be called inside the lead's team context.
 */
final class DraftMessageAction
{
    private const string PROMPT_KEY = 'outreach.draft_message';

    public function __construct(
        private readonly AiClient $ai,
        private readonly OutreachGuard $guard,
        private readonly OutreachPayloadBuilder $payload,
    ) {}

    /**
     * @throws OutreachBlockedException when the channel is refused
     * @throws OutreachException when the model returns nothing usable
     */
    public function handle(Team $team, Lead $lead, User $member, DraftMessageData $data): OutreachMessage
    {
        $contact = $this->contact($lead, $data->leadContactId);

        // The **compliance** half of the gate only: the lead's restriction and whether anything is
        // suppressed. The configuration half — no recipient chosen yet, no verified sender,
        // sending switched off — deliberately does not block a draft, because a member can still
        // copy the text into their own mailbox. See OutreachGuard::assertCompliance().
        $this->guard->assertCompliance($team, $lead, $data->channel, $contact);

        $template = $this->template($data->templateId, $data);
        $restriction = OutreachRestriction::fromRaw($lead->raw);

        // --- External work, outside any transaction ---
        $draft = $this->write($team, $lead, $contact, $data, $template, $restriction);

        return DB::transaction(function () use (
            $team, $lead, $contact, $member, $data, $template, $draft
        ): OutreachMessage {
            $message = OutreachMessage::query()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'lead_contact_id' => $contact?->getKey(),
                'outreach_template_id' => $template?->getKey(),
                'created_by' => $member->getKey(),
                'channel' => $data->channel,
                'tone' => $data->tone,
                'locale' => $data->locale,
                'subject' => $draft->draft->subject,
                'body' => $draft->draft->body(),
                'variants' => $draft->draft->toStoredVariants(),
                'prompt_key' => self::PROMPT_KEY,
                'prompt_version' => $draft->promptVersion,
                'model' => $draft->model,
                'cost_micros' => $draft->costMicros,
            ]);

            // Nothing on the timeline and nothing in the audit log: a draft is a private act that
            // reached nobody. The timeline entry is written when the message is actually used
            // (see SendOutreachEmailAction, LogContactAttemptAction) — a lead whose history filled
            // up with "someone thought about writing to you" would be unreadable.

            return $message;
        });
    }

    /**
     * Run the drafting prompt.
     *
     * Unlike scoring, there is no degraded mode: a draft is the entire deliverable, so output that
     * can't be used is a failure the member must be told about. Credit exhaustion and transport
     * failures are left to bubble — the first has its own friendly exception from the AI Platform,
     * the second is worth a retry.
     *
     * @throws OutreachException
     */
    private function write(
        Team $team,
        Lead $lead,
        ?LeadContact $contact,
        DraftMessageData $data,
        ?OutreachTemplate $template,
        ?OutreachRestriction $restriction,
    ): DraftResult {
        $profile = BusinessProfile::query()->where('team_id', $team->getKey())->first();

        try {
            $response = $this->ai->structured(self::PROMPT_KEY, [
                'business_profile' => $this->payload->businessBlock($profile),
                // Provider-sourced and page-extracted facts: data, never instructions.
                'lead' => $this->payload->leadBlock($lead, $contact),
                'channel' => $data->channel->label(),
                'channel_brief' => $this->payload->channelBlock($data->channel),
                'tone' => $data->tone->label(),
                'tone_brief' => $this->payload->toneBlock($data->tone, $restriction),
                'language' => $data->locale->label(),
                // The team typed this, so it goes in wrapped. `none` rather than an empty block, so
                // the placeholder never renders as a dangling heading the model tries to fill.
                'template' => $this->payload->templateBlock($template) ?? 'none — use your judgement.',
            ]);
        } catch (InvalidAiResponseException $e) {
            Log::channel('outreach')->warning('Drafting returned nothing usable.', [
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'channel' => $data->channel->value,
                'exception' => $e->getMessage(),
            ]);

            throw OutreachException::draftUnavailable();
        }

        $draft = MessageDraft::fromAi($response->data, $data->channel);

        // Schema-valid and still empty: every variant was for another channel, or every body was
        // blank. Rare, and not something to store as a draft with no words in it.
        if (! $draft instanceof MessageDraft) {
            Log::channel('outreach')->warning('Drafting returned no variant for the requested channel.', [
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'channel' => $data->channel->value,
            ]);

            throw OutreachException::draftUnavailable();
        }

        Log::channel('outreach')->info('Message drafted.', [
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'channel' => $data->channel->value,
            'tone' => $data->tone->value,
            'locale' => $data->locale->value,
            'variants' => count($draft->variants),
            'cost_micros' => $response->costMicros,
        ]);

        return new DraftResult($draft, $response->promptVersion, $response->model, $response->costMicros);
    }

    /**
     * The contact to address, read inside the team's scope so another team's contact id resolves to
     * null rather than to their row.
     */
    private function contact(Lead $lead, ?int $contactId): ?LeadContact
    {
        if ($contactId === null) {
            return null;
        }

        return LeadContact::query()
            ->where('lead_id', $lead->getKey())
            ->find($contactId);
    }

    /**
     * The template to brief the model with: the one asked for, or the team's default for this
     * channel. A default is applied only when nothing was asked for — a member who picked
     * "no template" meant it.
     */
    private function template(?int $templateId, DraftMessageData $data): ?OutreachTemplate
    {
        if ($templateId !== null) {
            return OutreachTemplate::query()->find($templateId);
        }

        return OutreachTemplate::query()->defaultFor($data->channel)->first();
    }
}
