<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Enums\PlaybookNotifyAudience;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for creating or updating a playbook (S14).
 *
 * `rules()` sits beside the shape it validates so the web and API endpoints cannot drift on what a
 * rule may be — the arrangement `SignalWatchData`, `SignalDefinitionData` and `SignalWatchlistData`
 * already use.
 *
 * {@see self::problems()} is the other half, and the reason it is PHP rather than more rules is
 * worth stating. What makes a step *valid* depends on its own kind: a stage move needs a stage, a
 * draft needs a channel the deployment offers, a task needs a title. Expressing that in Laravel's
 * rule language over an array of objects means `required_if:actions.*.kind,move_to_pipeline_stage`
 * repeated eight times — unreadable, and subtly wrong the first time somebody reorders the array.
 * So the shape is validated as a union by `rules()` and the semantics in one readable method that
 * returns `field => message` pairs, which the FormRequest merges into its own error bag and the
 * Action asserts on for the callers that have no form.
 */
final class SignalPlaybookData extends Data
{
    public const int MAX_NAME = 120;

    /**
     * @param  list<SignalPlaybookStep>  $steps
     */
    public function __construct(
        public readonly string $name,
        public readonly SignalPlaybookConditions $conditions,
        public readonly array $steps,
        public readonly PlaybookMode $mode = PlaybookMode::DryRun,
        public readonly bool $isActive = true,
        public readonly bool $stopAfterFirstMatch = false,
        public readonly int $priority = 100,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $conditions = $validated['conditions'] ?? [];
        $actions = $validated['actions'] ?? [];

        return new self(
            name: Str::limit(Str::squish((string) ($validated['name'] ?? '')), self::MAX_NAME, '') ?: 'My rule',
            conditions: SignalPlaybookConditions::fromStored(is_array($conditions) ? $conditions : []),
            steps: self::steps(is_array($actions) ? $actions : []),
            // **Rehearsal is the default**, and it is the only default in this phase that is a
            // safety decision rather than a convenience one: a rule created by an API caller who
            // did not think about `mode` should watch rather than act.
            mode: PlaybookMode::tryFrom(
                is_string($validated['mode'] ?? null) ? $validated['mode'] : '',
            ) ?? PlaybookMode::DryRun,
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            stopAfterFirstMatch: (bool) ($validated['stop_after_first_match'] ?? false),
            priority: max(0, min(1000, (int) ($validated['priority'] ?? 100))),
        );
    }

    /**
     * The attributes for the model, ready to create or fill with.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'is_active' => $this->isActive,
            'mode' => $this->mode,
            'conditions' => $this->conditions->toStored(),
            'actions' => array_map(
                static fn (SignalPlaybookStep $step): array => $step->toStored(),
                $this->steps,
            ),
            'stop_after_first_match' => $this->stopAfterFirstMatch,
            'priority' => $this->priority,
        ];
    }

    /**
     * What is wrong with this rule that a type cannot express, as `field => message`.
     *
     * Empty means it may be saved. The fields are addressed the way the form posts them
     * (`actions.2.params.stage_id`), so the error summary's links move focus to the control that is
     * wrong rather than to the top of a long builder.
     *
     * @param  list<string>  $channels  The drafting channels this deployment offers, from
     *                                  `SignalOutreachDrafter`. Signals does not know Outreach's
     *                                  enum, so the vocabulary is passed in rather than restated.
     * @param  list<string>  $tones
     * @return array<string, string>
     */
    public function problems(array $channels, array $tones): array
    {
        $problems = [];
        $seen = [];

        foreach ($this->steps as $index => $step) {
            $field = 'actions.'.$index.'.params';
            $kind = $step->kind;

            if (! $kind->isRepeatable() && in_array($kind->value, $seen, strict: true)) {
                // Said at the step rather than at the top, so the duplicate is the one highlighted.
                $problems['actions.'.$index.'.kind'] = (string) __('This step is already in the rule. Remove one of them.');
            }

            $seen[] = $kind->value;

            if ($kind === PlaybookActionKind::AssignToMember && $step->memberIds() === []) {
                $problems[$field.'.user_ids'] = (string) __('Choose at least one person to assign the lead to.');
            }

            if ($kind === PlaybookActionKind::MoveToPipelineStage && $step->stageId() === null) {
                $problems[$field.'.stage_id'] = (string) __('Choose the stage to move the lead to.');
            }

            if ($kind === PlaybookActionKind::AddTag && $step->tags() === []) {
                $problems[$field.'.tags'] = (string) __('Add at least one label.');
            }

            if ($kind === PlaybookActionKind::CreateTask && $step->title() === null) {
                $problems[$field.'.title'] = (string) __('Give the task a title.');
            }

            if ($kind === PlaybookActionKind::Notify
                && $step->notifyAudience() === PlaybookNotifyAudience::Named
                && $step->memberIds() === []) {
                $problems[$field.'.user_ids'] = (string) __('Choose who to tell.');
            }

            if ($kind === PlaybookActionKind::DraftOutreach) {
                $this->draftProblems($problems, $field, $step, $channels, $tones);
            }
        }

        // Converting *and* attaching is a rule that cannot mean both. Whichever runs first wins and
        // the second reports "already a lead", which works but is not what anybody intended to
        // build — so say so here instead of leaving them to read it in the log.
        if (in_array(PlaybookActionKind::ConvertToLead->value, $seen, strict: true)
            && in_array(PlaybookActionKind::AttachToMatchedLead->value, $seen, strict: true)) {
            $problems['actions'] = (string) __('A rule can either make a new lead or add to one you already have — not both.');
        }

        return $problems;
    }

    /**
     * The drafting step's two values, checked against what this deployment actually offers.
     *
     * An empty vocabulary means nothing can write drafts here at all, which is a different message:
     * the member has not got the step wrong, the deployment has not got drafting.
     *
     * @param  array<string, string>  $problems
     * @param  list<string>  $channels
     * @param  list<string>  $tones
     */
    private function draftProblems(array &$problems, string $field, SignalPlaybookStep $step, array $channels, array $tones): void
    {
        if ($channels === [] || $tones === []) {
            $problems[$field.'.channel'] = (string) __('Drafting is not available on this account.');

            return;
        }

        if (! in_array((string) $step->channel(), $channels, strict: true)) {
            $problems[$field.'.channel'] = (string) __('Choose how the approach should be written.');
        }

        if (! in_array((string) $step->tone(), $tones, strict: true)) {
            $problems[$field.'.tone'] = (string) __('Choose the tone to write it in.');
        }
    }

    /**
     * @param  array<int, mixed>  $actions
     * @return list<SignalPlaybookStep>
     */
    private static function steps(array $actions): array
    {
        $steps = [];

        foreach ($actions as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $step = SignalPlaybookStep::fromStored($raw);

            if ($step instanceof SignalPlaybookStep) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $maxActions = max(1, (int) config('signals.playbooks.max_actions', 8));

        return [
            'name' => ['required', 'string', 'max:'.self::MAX_NAME],
            'mode' => ['required', 'string', 'in:'.implode(',', PlaybookMode::values())],
            'is_active' => ['boolean'],
            'stop_after_first_match' => ['boolean'],
            // Bounded both ways so the board's order stays a small integer somebody can reason
            // about, rather than a 32-bit number two rules happen to share.
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            ...SignalPlaybookConditions::rules(),
            ...SignalPlaybookStep::rules($maxActions),
        ];
    }
}
