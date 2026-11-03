<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookAssignStrategy;
use App\Modules\Signals\Enums\PlaybookNotifyAudience;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Support\Playbook\PlaybookStepHandler;
use Illuminate\Support\Str;

/**
 * One step of a playbook: what to do, and the handful of values it needs (S14).
 *
 * **One class rather than eight.** The eight kinds differ in what they *do* — which is why there is
 * a {@see PlaybookStepHandler} per kind — but what they need from the member is in every case a few
 * ids, a short string or a small list. A DTO per kind would be eight files of two properties each
 * and eight places for the "an id from another team is never matched" rule to live.
 *
 * What makes that safe is that nothing reads `params` directly. The accessors below narrow every
 * value on the way out — ids to positive integers, lists to bounded distinct lists, strings to
 * squished and truncated ones — so a handler receives a type rather than whatever JSON held. The
 * same values were validated on the way in by {@see self::rules()}; this is the braces to that belt,
 * and it is what makes a row written by an older release safe to read.
 */
final readonly class SignalPlaybookStep
{
    /** Most members one step may name. Beyond this it is not a rule, it is a mailing list. */
    public const int MAX_MEMBERS = 20;

    /** Most tags one step may add. The workspace caps a lead at twenty; a rule adds a few. */
    public const int MAX_TAGS = 3;

    /** Longest a task title may be, matching `LeadTaskData`'s own clamp. */
    public const int MAX_TITLE = 160;

    /** Furthest ahead a task may be scheduled. A year is already beyond useful. */
    public const int MAX_DUE_DAYS = 365;

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public PlaybookActionKind $kind,
        public array $params = [],
    ) {}

    /**
     * Read a step back out of the stored JSON, or null when this build does not know its kind.
     *
     * Null rather than an exception: a rule built on a newer release and read by a rolled-back one
     * should have the rest of its steps honoured, not fail every signal. The caller counts what it
     * dropped — see {@see SignalPlaybook::hasUnknownSteps()}.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $kind = PlaybookActionKind::tryFrom(
            is_string($stored['kind'] ?? null) ? $stored['kind'] : '',
        );

        if ($kind === null) {
            return null;
        }

        $params = $stored['params'] ?? [];

        return new self($kind, is_array($params) ? $params : []);
    }

    /**
     * @return array{kind: string, params: array<string, mixed>}
     */
    public function toStored(): array
    {
        return ['kind' => $this->kind->value, 'params' => $this->params];
    }

    /**
     * How the lead's assignees are chosen. Named when the rule says nothing, because handing one
     * lead to everybody named is the less surprising of the two.
     */
    public function assignStrategy(): PlaybookAssignStrategy
    {
        return PlaybookAssignStrategy::tryFrom($this->string('strategy') ?? '')
            ?? PlaybookAssignStrategy::Named;
    }

    /**
     * Who a notify step tells. The lead's owners when the rule says nothing — the audience that is
     * right most often, and the one that cannot turn a rule into team-wide noise.
     */
    public function notifyAudience(): PlaybookNotifyAudience
    {
        return PlaybookNotifyAudience::tryFrom($this->string('audience') ?? '')
            ?? PlaybookNotifyAudience::LeadOwners;
    }

    /**
     * The members this step names, as a bounded list of distinct positive ids.
     *
     * Not checked against the team here: the handlers resolve through the team's own membership, so
     * a foreign id resolves to nobody rather than to somebody else's colleague.
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        return $this->ids('user_ids', self::MAX_MEMBERS);
    }

    /** The board column to move the lead to, or null. */
    public function stageId(): ?int
    {
        return $this->positiveInt('stage_id');
    }

    /**
     * The labels to add.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = [];
        $raw = $this->params['tags'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $tag = Str::limit(Str::squish($entry), 40, '');

            if ($tag === '' || in_array($tag, $tags, strict: true)) {
                continue;
            }

            $tags[] = $tag;

            if (count($tags) >= self::MAX_TAGS) {
                break;
            }
        }

        return $tags;
    }

    /** The channel a draft is for, as the drafting contract's own value. */
    public function channel(): ?string
    {
        return $this->string('channel');
    }

    /** The register a draft is written in, as the drafting contract's own value. */
    public function tone(): ?string
    {
        return $this->string('tone');
    }

    /** A task's title, clamped the way the workspace clamps its own. */
    public function title(): ?string
    {
        $title = $this->string('title');

        return $title === null ? null : Str::limit($title, self::MAX_TITLE, '');
    }

    /**
     * How many days ahead the task is due, or null for "no due date".
     *
     * Days rather than a date, because a rule is a standing instruction: a date would be in the
     * past by the second week.
     */
    public function dueInDays(): ?int
    {
        $days = $this->positiveInt('due_in_days');

        return $days === null ? null : min($days, self::MAX_DUE_DAYS);
    }

    /** Whether a task goes to whoever is working the lead rather than to a named member. */
    public function assignToLeadOwner(): bool
    {
        return (bool) ($this->params['assign_to_lead_owner'] ?? false);
    }

    /**
     * The validation rules for the whole `actions` array.
     *
     * Deliberately **not** per-kind conditional rules. Laravel's `required_if` over an array of
     * objects whose required keys depend on a sibling's value is unreadable and, worse, easy to get
     * subtly wrong — so the shape is validated as a union here and the *semantic* check ("a stage
     * move needs a stage") lives in {@see SignalPlaybookData::problems()}, which the save path
     * refuses on. One place, in PHP, testable.
     *
     * @return array<string, mixed>
     */
    public static function rules(int $maxActions): array
    {
        return [
            'actions' => ['required', 'array', 'min:1', 'max:'.$maxActions],
            'actions.*.kind' => ['required', 'string', 'in:'.implode(',', PlaybookActionKind::values())],
            'actions.*.params' => ['nullable', 'array'],
            'actions.*.params.strategy' => ['nullable', 'string', 'in:'.implode(',', PlaybookAssignStrategy::values())],
            'actions.*.params.audience' => ['nullable', 'string', 'in:'.implode(',', PlaybookNotifyAudience::values())],
            'actions.*.params.user_ids' => ['nullable', 'array', 'max:'.self::MAX_MEMBERS],
            'actions.*.params.user_ids.*' => ['integer', 'min:1'],
            'actions.*.params.stage_id' => ['nullable', 'integer', 'min:1'],
            'actions.*.params.tags' => ['nullable', 'array', 'max:'.self::MAX_TAGS],
            'actions.*.params.tags.*' => ['string', 'max:40'],
            // The channel and tone are checked against the drafting contract's own vocabulary in
            // `problems()`, not here: Signals does not know Outreach's enums, and hard-coding their
            // values in a rule would be a copy that drifts.
            'actions.*.params.channel' => ['nullable', 'string', 'max:40'],
            'actions.*.params.tone' => ['nullable', 'string', 'max:40'],
            'actions.*.params.title' => ['nullable', 'string', 'max:'.self::MAX_TITLE],
            // `min:1`, not `min:0`: "due in no days" and "no due date" would be the same posted
            // value, and a task due the instant it is created is overdue by the time anyone reads
            // the notification. Tomorrow is the soonest a rule may ask for.
            'actions.*.params.due_in_days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DUE_DAYS],
            'actions.*.params.assign_to_lead_owner' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<int>
     */
    private function ids(string $key, int $max): array
    {
        $raw = $this->params[$key] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $entry) {
            if (! is_numeric($entry)) {
                continue;
            }

            $id = (int) $entry;

            if ($id > 0 && ! in_array($id, $ids, strict: true)) {
                $ids[] = $id;
            }

            if (count($ids) >= $max) {
                break;
            }
        }

        return $ids;
    }

    private function positiveInt(string $key): ?int
    {
        $value = $this->params[$key] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function string(string $key): ?string
    {
        $value = $this->params[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = Str::squish($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
