<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Data\DraftIcp;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Exceptions\IcpException;
use App\Modules\ICP\Http\Requests\ComposeIcpRequest;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a sentence into a **draft** ideal customer profile: "we are a design agency looking for
 * startup food businesses that need packaging branding or social media work".
 *
 * **It never saves anything.** It returns a {@see DraftIcp} the member reviews in the ordinary
 * editor and keeps through the ordinary save path — the arrangement ADR 0020 settled for the
 * Signals builder, for the same reason: the active profile grades every lead in the account and
 * shapes what discovery searches for, so a prompt that wrote one directly would be a prompt with
 * write access to the team's grading.
 *
 * The inversion is the hard part, not the formatting. A member describes **themselves** as much as
 * their customer ("we are a design agency..."), and the failure this product has already had twice
 * — `business_intel.background_check` v2 and `lead_sourcing.shape_query` — is handing a design
 * studio a list of design studios. So the prompt's job is to return the industries that *buy* from
 * the member, and the Action hands it the team's own profile so it can tell the two apart.
 *
 * Two guards, each for a different reason:
 *
 * 1. **The AI credit allowance**, which the client meters — an exhausted team gets a clean refusal
 *    rather than a 500. There is deliberately **no plan capability** on this: the hand-written
 *    editor is the same screen, and one draft commits a team to nothing but a save. Signals gates
 *    its composer because each draft it writes is a standing instruction to spend money every day
 *    (plan decision #1); an ICP is not, so gating this would only make the cheaper plans worse than
 *    they were the day before it existed.
 * 2. **The schema, and then the rules.** The prompt's JSON schema bounds the shape and
 *    {@see DraftIcp::fromAi()} re-validates it against {@see IcpData::rules()}. A prompt describes
 *    a constraint; it does not enforce one.
 *
 * The brief is wrapped as {@see UntrustedContent} and clamped in **characters** before it goes
 * anywhere (ADR 0017): it is free text from a customer heading into a reasoning-tier prompt, which
 * is both the injection surface and the cost surface. Per-member rate limiting is route middleware,
 * not here, because nothing but a person can reach this Action — there is no queued or scheduled
 * path to it, so ADR 0012's argument for putting a ceiling in a guard does not apply.
 */
final class ComposeIcpAction
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * @throws IcpException when the AI is unusable — no credit, or no schema-valid answer.
     */
    public function handle(Team $team, User $actor, string $brief): DraftIcp
    {
        $clean = Str::limit(Str::squish($brief), ComposeIcpRequest::MAX_BRIEF, '');

        if ($clean === '') {
            // Not a validation failure — the request already passed validation to get here. The
            // belt to its braces, and a refusal because an empty brief is a two-second fix.
            return DraftIcp::refused('Describe the customers you are looking for — a sentence is enough.');
        }

        try {
            // Inside the team's own context, because the AI client resolves the billed team, the
            // cache key and the credit guard from the **ambient** context rather than from an
            // argument. They agree for a web request; a caller with none would charge nobody.
            $response = $this->teamContext->run($team, fn () => $this->ai->structured('icp.compose_profile', [
                'markets' => $this->marketsPrompt($team),
                'business_profile' => $this->profileSummary($team),
                'criterion_types' => $this->criterionTypes(),
                // The one untrusted value: everything else here is ours or the team's own data.
                'brief' => UntrustedContent::from($clean),
            ]));
        } catch (AiCreditExceededException) {
            throw IcpException::composerUnavailable();
        } catch (InvalidAiResponseException) {
            // The client already retried once. A second schema-invalid answer is nothing a member
            // can act on, so it is reported as the composer being unavailable rather than as their
            // description being wrong.
            Log::warning('The ICP composer returned no schema-valid draft.', [
                'team_id' => $team->getKey(),
                'user_id' => $actor->getKey(),
                'brief_length' => mb_strlen($clean),
            ]);

            throw IcpException::composerUnavailable();
        }

        // Initialised here because it is passed by reference: an undefined variable would arrive as
        // null and fail the parameter's own type.
        $dropped = 0;

        $draft = DraftIcp::fromAi($response->data, $dropped);

        // Counts and costs only, never the brief or the draft's prose (CLAUDE.md §2, ADR 0017).
        // `dropped` is the one worth watching: a row the save path would not take is repaired away
        // silently, so a prompt that started losing half its criteria would otherwise look fine.
        Log::info('Drafted an ideal customer profile from a description.', [
            'team_id' => $team->getKey(),
            'user_id' => $actor->getKey(),
            'viable' => $draft->viable,
            'criteria' => $draft->profile === null ? 0 : count($draft->profile->criteria),
            'dropped_criteria' => $dropped,
            'cost_micros' => $response->costMicros,
            'prompt_version' => $response->promptVersion,
        ]);

        return $draft;
    }

    /**
     * The market this team sells into, named rather than coded: a prompt reading English learns
     * less from "uae" than from "United Arab Emirates".
     */
    private function marketsPrompt(Team $team): string
    {
        $market = Market::tryFrom($team->market);

        return $market instanceof Market ? $market->label() : 'the United Kingdom and the United Arab Emirates';
    }

    /**
     * What this team sells, in one line — the context that makes the inversion possible.
     *
     * Absent rather than invented when onboarding has not run: a model told nothing works from the
     * brief alone, which is the right failure. A model told something made up drafts a confident
     * profile for the wrong business.
     */
    private function profileSummary(Team $team): string
    {
        $profile = BusinessProfile::query()->where('team_id', $team->getKey())->first();

        if (! $profile instanceof BusinessProfile) {
            return 'Not yet described.';
        }

        $parts = array_filter([
            $profile->company_name,
            $profile->industry,
            $profile->value_proposition,
            $profile->products_services !== null ? implode(', ', $profile->products_services) : null,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        return $parts === []
            ? 'Not yet described.'
            : Str::limit(Str::squish(implode(' — ', $parts)), 500, '');
    }

    /**
     * The criterion types with their own hints, so the prompt names the dimensions the editor shows
     * and writes each value in the shape that dimension expects. Built from the enum rather than
     * restated in the prompt body: a new case must not need a new prompt version to be usable.
     */
    private function criterionTypes(): string
    {
        return collect(CriterionType::cases())
            ->map(static fn (CriterionType $type): string => sprintf(
                '- %s: %s (%s)%s',
                $type->value,
                $type->label(),
                $type->placeholder(),
                $type->isExclusion() ? ' - never weighted' : '',
            ))
            ->implode("\n");
    }
}
