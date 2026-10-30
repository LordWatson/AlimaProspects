<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Data;

use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Services\OutreachGuard;
use Spatie\LaravelData\Data;

/**
 * {@see OutreachGuard}'s answer for one channel on one lead (Phase 11): may it be used, and if not,
 * which rule said no.
 *
 * `allowed` is the only thing a caller may branch on. The reasons exist to be shown and audited —
 * a caller that decides for itself that, say, a missing sender identity is "probably fine" has
 * re-implemented the gate, badly.
 */
final class OutreachDecision extends Data
{
    /**
     * @param  list<OutreachBlockReason>  $reasons  Every rule that refused, not just the first, so
     *                                              a member isn't sent round a loop fixing one
     *                                              problem at a time.
     */
    public function __construct(
        public readonly OutreachChannel $channel,
        public readonly bool $allowed,
        public readonly array $reasons = [],
    ) {}

    public static function allow(OutreachChannel $channel): self
    {
        return new self($channel, true);
    }

    /**
     * @param  list<OutreachBlockReason>  $reasons
     */
    public static function block(OutreachChannel $channel, array $reasons): self
    {
        // A block with no reason would leave a member staring at a disabled button, so refuse to
        // build one: an unexplained refusal is a bug, and it should look like one here rather than
        // on the screen.
        if ($reasons === []) {
            throw new \InvalidArgumentException('A blocked outreach decision must carry at least one reason.');
        }

        return new self($channel, false, $reasons);
    }

    /**
     * Whether any of the refusals is a compliance one. A compliance block is final; the rest are
     * things the team can go and fix, which is a different sentence on screen.
     */
    public function isComplianceBlock(): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason->isCompliance()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first refusal, for a one-line message and for the audit entry.
     */
    public function primaryReason(): ?OutreachBlockReason
    {
        return $this->reasons[0] ?? null;
    }

    /**
     * The shape the screens and the API read. Labels and guidance are translation keys, not
     * finished strings (EN/AR, ADR 0007).
     *
     * @return array{channel: string, allowed: bool, reasons: list<array{value: string, label: string, guidance: string, is_compliance: bool}>}
     */
    public function toPayload(): array
    {
        return [
            'channel' => $this->channel->value,
            'allowed' => $this->allowed,
            'reasons' => array_map(static fn (OutreachBlockReason $reason): array => [
                'value' => $reason->value,
                'label' => $reason->label(),
                'guidance' => $reason->guidance(),
                'is_compliance' => $reason->isCompliance(),
            ], $this->reasons),
        ];
    }
}
