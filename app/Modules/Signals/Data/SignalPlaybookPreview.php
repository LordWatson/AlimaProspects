<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

/**
 * What a rule **would have done** to the signals a team already has (S14).
 *
 * This is S9's preview idea applied to automation, and it is the reason anybody is brave enough to
 * switch a rule on. The difference from S9's preview is that this one **spends nothing**: the
 * evaluator is deterministic and reads rows the team already paid for, so there is no ledger row,
 * no budget and no cap — only a window (`signals.playbooks.preview_days`).
 *
 * `matched` and `considered` are both needed, and the ratio is the point. "It matches 4 of 120" is a
 * rule somebody can switch on; "it matches 118 of 120" is the one that would convert the whole feed,
 * and a count with no denominator would hide the difference.
 *
 * {@see self::$wouldExceedConversions} and {@see self::$wouldExceedDrafts} are the honest sting in
 * the tail: a rule that matches more signals than one job is allowed to convert or draft for would
 * have been refused partway through, and knowing that before switching it on is better than reading
 * it in the log afterwards.
 */
final readonly class SignalPlaybookPreview
{
    /**
     * @param  list<PreviewedSignal>  $samples  A few of the matches, newest first.
     */
    public function __construct(
        public int $considered,
        public int $matched,
        public int $days,
        public bool $matchesEverything,
        public bool $wouldExceedConversions,
        public bool $wouldExceedDrafts,
        public array $samples = [],
    ) {}

    /**
     * The shape the screens and the API read.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'considered' => $this->considered,
            'matched' => $this->matched,
            'days' => $this->days,
            'matches_everything' => $this->matchesEverything,
            'would_exceed_conversions' => $this->wouldExceedConversions,
            'would_exceed_drafts' => $this->wouldExceedDrafts,
            'samples' => array_map(
                static fn (PreviewedSignal $sample): array => $sample->toPayload(),
                $this->samples,
            ),
        ];
    }
}
