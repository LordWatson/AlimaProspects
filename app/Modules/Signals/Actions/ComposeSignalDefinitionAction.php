<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Signals\Data\DraftSignalDefinition;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a sentence into a **draft** signal type (S9): "tell me when a UK manufacturer changes hands".
 *
 * **It never saves anything.** The AI drafts and the member owns — they read the draft, edit it,
 * preview it and then press save, which is a different Action. That separation is the product
 * decision as much as the safety one: a composer that wrote directly to a team's account would be
 * a prompt with write access to a standing instruction to spend money.
 *
 * Three guards, in this order, and each for a different reason:
 *
 * 1. **The capability** ({@see Capability::SignalComposer}, plan decision #1). Checked here rather
 *    than only in middleware, the same arrangement every other capability uses: the route check
 *    gives a member a sentence, this makes the refusal true.
 * 2. **The AI credit allowance**, which the client meters — an exhausted team gets a clean refusal
 *    rather than a 500, because "you are out of credit" is an answer and a stack trace is not.
 * 3. **The schema, and then the rules.** The prompt's JSON schema bounds the shape and
 *    {@see DraftSignalDefinition::fromAi()} re-validates it against the save path's own
 *    `SignalDefinitionData::rules()`. A prompt describes a constraint; it does not enforce one.
 *
 * The member's brief is wrapped as {@see UntrustedContent} and clamped in **characters** before it
 * goes anywhere (ADR 0017): it is free text from a customer heading into a reasoning-tier prompt,
 * which is both the injection surface and the cost surface.
 *
 * Per-member rate limiting is route middleware (`throttle:signals-compose`), not here, because
 * nothing but a person can reach this Action — there is no queued or scheduled path to it, so the
 * ADR 0012 argument for putting a ceiling in the guard does not apply. The *team's* ceiling is the
 * AI allowance, which is already a guard.
 */
final class ComposeSignalDefinitionAction
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly CapabilityGate $capabilities,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * @throws CapabilityException when the team's plan does not include the composer.
     * @throws SignalsException when the AI is unusable — no credit, or no schema-valid answer.
     */
    public function handle(Team $team, User $actor, string $brief): DraftSignalDefinition
    {
        if (! $this->capabilities->allows($team, Capability::SignalComposer)) {
            throw CapabilityException::unavailable(Capability::SignalComposer);
        }

        $clean = Str::limit(Str::squish($brief), (int) config('signals.composer.max_brief', 1_000), '');

        if ($clean === '') {
            // Not a validation failure — the request already passed validation to get here. This is
            // the belt to its braces, and a refusal rather than an exception because an empty brief
            // is a thing a member can fix in two seconds.
            return DraftSignalDefinition::refused('Describe the moment you want to hear about — one sentence is enough.');
        }

        try {
            // Inside the team's own context, because the AI client resolves the billed team, the
            // cache key and the credit guard from the **ambient** context rather than from an
            // argument. They agree for a web request; a caller with none would charge nobody and
            // turn the credit guard into a no-op.
            $response = $this->teamContext->run($team, fn () => $this->ai->structured('signals.compose_definition', [
                'markets' => $this->marketsPrompt($team),
                'business_profile' => $this->profileSummary($team),
                'existing_types' => $this->existingTypes($team),
                'categories' => implode(', ', SignalCategory::values()),
                'icons' => implode(', ', SignalIcon::values()),
                // The one untrusted value, and the only reason this prompt needs wrapping: every
                // other input here is ours or the team's own structured data.
                'brief' => UntrustedContent::from($clean),
            ]));
        } catch (AiCreditExceededException) {
            throw SignalsException::composerUnavailable();
        } catch (InvalidAiResponseException) {
            // The client already retried once. A second schema-invalid answer is not something a
            // member can act on, so it is reported as the composer being unavailable rather than as
            // their description being wrong.
            Log::channel('signals')->warning('The signal composer returned no schema-valid draft.', [
                'team_id' => $team->getKey(),
                'user_id' => $actor->getKey(),
                'brief_length' => mb_strlen($clean),
            ]);

            throw SignalsException::composerUnavailable();
        }

        $draft = DraftSignalDefinition::fromAi($response->data);

        // Counts and lengths only, never the brief or the draft's prose — the logging rule is a
        // compliance control here rather than a style preference (CLAUDE.md §2, ADR 0017).
        Log::channel('signals')->info('Drafted a signal type from a description.', [
            'team_id' => $team->getKey(),
            'user_id' => $actor->getKey(),
            'viable' => $draft->viable,
            'templates' => $draft->definition === null ? 0 : count($draft->definition->queryTemplates),
            'cost_micros' => $response->costMicros,
            'prompt_version' => $response->promptVersion,
        ]);

        return $draft;
    }

    /**
     * The markets this team sells into, for a draft that suits them.
     *
     * The team's own market, named rather than coded, because the prompt is reading English and
     * "uae" tells a model less than "United Arab Emirates" does.
     */
    private function marketsPrompt(Team $team): string
    {
        $market = Market::tryFrom($team->market);

        return $market instanceof Market ? $market->label() : 'the United Kingdom and the United Arab Emirates';
    }

    /**
     * What this team sells, in one line, for judging relevance.
     *
     * The same summary the extraction prompt gets, and absent rather than invented when onboarding
     * has not run: a model told nothing about the business drafts a generic type, which is the right
     * failure. A model told something made up drafts a confident wrong one.
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
     * The team's existing types, so the composer does not hand them a fourth "Acquisition".
     *
     * Names and keys only. The templates of an existing type would be useful context and are
     * deliberately left out: they are a team's own tuning, and feeding them in would make every new
     * draft a variation on the first one somebody wrote.
     */
    private function existingTypes(Team $team): string
    {
        $types = SignalDefinition::query()
            ->where('team_id', $team->getKey())
            ->orderBy('id')
            ->limit(30)
            ->get(['key', 'name']);

        if ($types->isEmpty()) {
            return 'None yet.';
        }

        return $types
            ->map(static fn (SignalDefinition $definition): string => '- '.$definition->name.' ('.$definition->key.')')
            ->implode("\n");
    }
}
