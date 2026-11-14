<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiPlatformException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Support\SellerBrief;
use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Enums\SignalSuggestionStatus;
use App\Modules\Signals\Jobs\TestSignalSuggestionJob;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalSuggestionBatch;
use App\Modules\Signals\Services\PlatformPresetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Asks which trigger events tend to come before a company needs what this business sells, and
 * turns the answer into suggestions a member can review (Signals v3 P2).
 *
 * **It drafts and never saves** — ADR 0020's rule for an AI that writes configuration, applied to
 * a model that was not even given a brief. Three bounds on what comes back, none of them the
 * prompt:
 *
 * 1. **The save path's own rules.** A drafted type is validated against
 *    {@see SignalDefinitionData::rules()}; one the form would refuse is dropped.
 * 2. **A library type is named, not rewritten.** Where the idea is one the library already
 *    covers, the model returns its key and the suggestion is that type as shipped — its templates
 *    have been run against live search, a fresh draft's have not. A key that is not in the library,
 *    or one the team already has, is dropped.
 * 3. **Nothing is offered untested if it can be tested.** Each suggestion is queued for a sample
 *    search ({@see TestSignalSuggestionAction}); the screen ranks them by what that found.
 *
 * The AI call is external work and runs outside the transaction.
 */
final class DraftSignalSuggestionsAction
{
    public const string PROMPT_KEY = 'signals.propose_definitions';

    private const int MAX_TITLE = 120;

    private const int MAX_RATIONALE = 300;

    public function __construct(
        private readonly AiClient $ai,
        private readonly TeamContext $teamContext,
        private readonly PlatformPresetReader $presets,
    ) {}

    public function handle(SignalSuggestionBatch $batch, bool $queueTests = true): SignalSuggestionBatch
    {
        $team = Team::query()->findOrFail($batch->team_id);

        return $this->teamContext->run($team, function () use ($batch, $team, $queueTests): SignalSuggestionBatch {
            if ($batch->status !== SignalSuggestionStatus::Pending) {
                return $batch;
            }

            $teamId = (int) $team->getKey();
            $market = SignalWatchData::marketFor($team->market);
            $library = $this->presets->active($market)->keyBy('key');
            $mine = SignalDefinition::query()->where('team_id', $teamId)->get(['key', 'name', 'preset_key']);

            try {
                $response = $this->ai->structured(self::PROMPT_KEY, [
                    'markets' => $market->label(),
                    // The team's own description, partly read off its website: data, never
                    // instructions. The same goes for the profile a member typed.
                    'business_profile' => UntrustedContent::from(
                        SellerBrief::for(BusinessProfile::query()->where('team_id', $teamId)->first()) ?? 'Not yet described.',
                    ),
                    'ideal_customer' => UntrustedContent::from($this->icpBlock($teamId)),
                    // Names a member typed, so wrapped like everything else of theirs.
                    'existing_types' => UntrustedContent::from($mine->isEmpty()
                        ? 'None yet.'
                        : $mine->map(static fn (SignalDefinition $d): string => '- '.$d->name)->implode("\n")),
                    'library' => $library
                        ->reject(static fn (SignalDefinition $preset): bool => $mine->contains('preset_key', $preset->key))
                        ->take(60)
                        ->map(static fn (SignalDefinition $p): string => sprintf('- %s — %s: %s', $p->key, $p->name, Str::limit((string) $p->description, 140, '')))
                        ->implode("\n"),
                    'categories' => implode(', ', SignalCategory::values()),
                    'icons' => implode(', ', SignalIcon::values()),
                    'max' => (string) $this->max(),
                ]);
            } catch (AiPlatformException $e) {
                Log::channel('signals')->warning('Signal suggestions could not be drafted.', [
                    'team_id' => $teamId,
                    'signal_suggestion_batch_id' => $batch->getKey(),
                    'error' => $e::class,
                ]);

                $batch->forceFill(['status' => SignalSuggestionStatus::Failed, 'error_code' => $e->errorCode()])->save();

                return $batch;
            }

            $suggestions = $this->usable((array) $response->get('proposals', []), array_map(strval(...), array_values($library->keys()->all())), $mine->all());

            DB::transaction(static fn () => $batch->forceFill([
                'status' => SignalSuggestionStatus::Ready,
                'suggestions' => $suggestions,
                'cost_micros' => $response->costMicros,
                'prompt_version' => $response->promptVersion,
            ])->save());

            // Counts and cost; never the drafted text or anything about the business.
            Log::channel('signals')->info('Drafted signal type suggestions.', [
                'team_id' => $teamId,
                'signal_suggestion_batch_id' => $batch->getKey(),
                'offered' => count((array) $response->get('proposals', [])),
                'kept' => count($suggestions),
                'cost_micros' => $response->costMicros,
                'prompt_version' => $response->promptVersion,
            ]);

            if ($queueTests) {
                foreach (array_keys($suggestions) as $index) {
                    TestSignalSuggestionJob::dispatch($teamId, (int) $batch->getKey(), $index);
                }
            }

            return $batch;
        });
    }

    /**
     * The proposals worth showing, as storable rows.
     *
     * @param  array<array-key, mixed>  $proposals
     * @param  list<string>  $libraryKeys  presets this team could add
     * @param  array<array-key, SignalDefinition>  $mine
     * @return list<array<string, mixed>>
     */
    private function usable(array $proposals, array $libraryKeys, array $mine): array
    {
        $taken = array_map(static fn (SignalDefinition $d): string => Str::lower(trim($d->name)), $mine);
        $cloned = array_filter(array_map(static fn (SignalDefinition $d): ?string => $d->preset_key, $mine));
        $out = [];

        foreach (array_slice($proposals, 0, $this->max()) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $title = Str::limit(Str::squish((string) ($raw['title'] ?? '')), self::MAX_TITLE, '');
            $rationale = Str::limit(Str::squish((string) ($raw['rationale'] ?? '')), self::MAX_RATIONALE, '');
            $presetKey = is_string($raw['preset_key'] ?? null) ? trim($raw['preset_key']) : '';

            if ($title === '' || $rationale === '') {
                continue;
            }

            $row = ['title' => $title, 'rationale' => $rationale, 'preset_key' => null, 'definition' => null,
                'state' => SignalSuggestionBatch::STATE_TESTING, 'preview' => null];

            if ($presetKey !== '') {
                // A key the library does not carry is a hallucination; one the team has is noise.
                if (! in_array($presetKey, $libraryKeys, true) || in_array($presetKey, $cloned, true) || isset($out['preset:'.$presetKey])) {
                    continue;
                }

                $out['preset:'.$presetKey] = [...$row, 'preset_key' => $presetKey];

                continue;
            }

            $validator = Validator::make(is_array($raw['definition'] ?? null) ? $raw['definition'] : [], SignalDefinitionData::rules());

            if ($validator->fails()) {
                continue;
            }

            /** @var array<string, mixed> $definition */
            $definition = $validator->validated();
            $name = Str::lower(trim((string) $definition['name']));

            if (in_array($name, $taken, true) || isset($out['draft:'.$name])) {
                continue;
            }

            $out['draft:'.$name] = [...$row, 'definition' => $definition];
        }

        return array_values($out);
    }

    /**
     * The active ideal customer profile as a few lines, or a sentence saying there is none.
     */
    private function icpBlock(int $teamId): string
    {
        $icp = Icp::query()->with('criteria')->where('team_id', $teamId)->where('is_active', true)->first();

        if (! $icp instanceof Icp || $icp->criteria->isEmpty()) {
            return 'No ideal customer profile yet.';
        }

        return $icp->criteria
            ->take(30)
            ->map(static fn (IcpCriterion $c): string => sprintf('- %s: %s', $c->type->value, Str::limit($c->value, 160, '')))
            ->implode("\n");
    }

    private function max(): int
    {
        return max(1, min(5, (int) config('signals.suggestions.max', 5)));
    }
}
