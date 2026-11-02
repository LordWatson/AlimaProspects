<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * What the AI composer drafted from a member's sentence — **not** a saved definition (S9).
 *
 * The AI drafts; the member owns. Nothing here is persisted until somebody has read it and pressed
 * save, which is why this is its own type rather than a `SignalDefinition` with no id: a draft is a
 * suggestion, and a suggestion that can be mistaken for a record is how a prompt ends up writing to
 * a customer's account.
 *
 * **A draft can only contain what a definition can hold.** The AI's output is run through
 * {@see SignalDefinitionData::rules()} — the same rules the save path uses — rather than trusted,
 * so a model that returns eleven templates, a 4,000-character rule or a category nobody defined
 * produces a validation failure here instead of a surprise three screens later. The schema bounds
 * it first and this bounds it again, for the reason the S6 security review gave: the schema is the
 * guard, and a guard with one layer is a guard somebody will route around.
 *
 * **`viable: false` is a first-class answer.** "Find me rich people in Dubai" is not an event, and a
 * composer that produced plausible templates for it would be worse than one that refuses: it would
 * spend a team's money every day forever on a search that cannot work. The reason is the member's to
 * read, not ours to hide.
 */
final class DraftSignalDefinition extends Data
{
    public const int MAX_REASON = 300;

    public const int MAX_SUMMARY = 300;

    public function __construct(
        public readonly bool $viable,
        /** Why not, when it is not. Null when it is. */
        public readonly ?string $reason,
        /** What the composer understood the member to be asking for, in its own words. */
        public readonly ?string $summary,
        public readonly ?SignalDefinitionData $definition,
    ) {}

    /**
     * A refusal, with the reason the member asked for.
     */
    public static function refused(string $reason): self
    {
        return new self(
            viable: false,
            reason: Str::limit(Str::squish($reason), self::MAX_REASON, ''),
            summary: null,
            definition: null,
        );
    }

    /**
     * Map the validated AI output to a draft, re-validating it against the save path's own rules.
     *
     * Returns a refusal rather than throwing when the model's draft cannot be a definition: the
     * member asked a reasonable question and got an unusable answer, which is a thing to tell them
     * about, not a 500. The failure is logged by the Action (counts and keys, never the prose).
     *
     * @param  array<string, mixed>  $output
     */
    public static function fromAi(array $output): self
    {
        if (($output['is_viable'] ?? false) !== true) {
            return self::refused(
                is_string($output['reason'] ?? null) && $output['reason'] !== ''
                    ? $output['reason']
                    : 'That does not describe an event Alima could watch the news for.',
            );
        }

        /** @var array<string, mixed> $draft */
        $draft = is_array($output['definition'] ?? null) ? $output['definition'] : [];

        $validator = Validator::make($draft, SignalDefinitionData::rules());

        if ($validator->fails()) {
            return self::refused('Alima drafted something it could not turn into a signal type. Try describing the event in one sentence.');
        }

        return new self(
            viable: true,
            reason: null,
            summary: is_string($output['summary'] ?? null)
                ? Str::limit(Str::squish($output['summary']), self::MAX_SUMMARY, '')
                : null,
            // Through `fromValidated` rather than built here, so a draft is clamped, lower-cased
            // and defaulted by exactly the code that does it for a hand-written one.
            definition: SignalDefinitionData::fromValidated($validator->validated()),
        );
    }

    /**
     * A draft made from data a member has already edited — the preview's way home.
     *
     * The preview flow posts the whole definition, previews it, and redirects: without handing the
     * draft back the form a member spent two minutes on would be empty underneath their results.
     * It is `viable` by construction, because it validated on the way in.
     */
    public static function of(SignalDefinitionData $definition): self
    {
        return new self(
            viable: true,
            reason: null,
            summary: null,
            definition: $definition,
        );
    }

    /**
     * An unsaved model carrying the draft, for the preview to build queries from.
     *
     * **`make()`, never `create()`.** The preview runs against a definition that has no row, which
     * is the whole reason {@see SignalQuerySpec} exists — and a draft that quietly saved itself in
     * order to be previewed would be the composer writing to the account after all.
     */
    public function toUnsavedDefinition(): SignalDefinition
    {
        $definition = $this->definition;

        if (! $definition instanceof SignalDefinitionData) {
            // Unreachable through the Action, which refuses a non-viable draft before here. Guarded
            // rather than asserted because the alternative is a null dereference in a paid path.
            throw new \LogicException('A non-viable draft has no definition to preview.');
        }

        $model = new SignalDefinition;
        $model->forceFill([
            ...$definition->toAttributes(),
            'key' => SignalDefinitionData::keyFor($definition->name),
        ]);

        return $model;
    }

    /**
     * A stable fingerprint of the draft, for the preview ledger.
     *
     * Hashed rather than stored: `signal_previews` needs to recognise a repeat, not keep the
     * member's words. Over the templates and the thresholds only — the fields that change what a
     * preview *does* — so renaming a draft does not read as a different search.
     */
    public function hash(): string
    {
        $definition = $this->definition;

        if (! $definition instanceof SignalDefinitionData) {
            return sha1('non-viable');
        }

        return sha1((string) json_encode([
            $definition->queryTemplates,
            $definition->includes,
            $definition->excludes,
            $definition->minConfidence,
            $definition->minRelevance,
        ]));
    }
}
