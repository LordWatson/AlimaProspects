<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Contracts\SignalOutreachDrafter;

/**
 * {@see SignalOutreachDrafter}'s answer: a draft was written for somebody to read, a rule refused
 * the approach, or drafting is not available here at all (S14).
 *
 * Three outcomes and no fourth, and the third is not a variant of the second. A **block** is a
 * statement about this prospect — suppressed, or carrying a prior-consent restriction — and it is
 * final. **Unavailable** is a statement about this deployment: the module that writes drafts is not
 * bound, or the rule named a channel and register this build does not offer. The team reads a
 * different sentence in each case, and collapsing them would tell somebody to go and get consent
 * when the real answer is that their sender address is not set up.
 *
 * `reasonLabel` and `reasonGuidance` are **translation keys** that came from the owning module's
 * own enum, not sentences this module wrote. That is the point of passing them through rather than
 * mapping them: *which* rule refused an approach is Outreach's judgement, and a second vocabulary
 * for it here would be the copy that drifts.
 */
final readonly class PlaybookDraftOutcome
{
    private function __construct(
        public bool $drafted,
        public bool $blocked,
        public bool $unavailable,
        /** The stored draft, so the log can link to it. */
        public ?int $messageId = null,
        /** The refusing rule's own stable key, e.g. `restricted`. */
        public ?string $reasonKey = null,
        public ?string $reasonLabel = null,
        public ?string $reasonGuidance = null,
        /** Whether the refusal is a compliance one — final — rather than configuration. */
        public bool $isCompliance = false,
    ) {}

    public static function drafted(int $messageId): self
    {
        return new self(drafted: true, blocked: false, unavailable: false, messageId: $messageId);
    }

    public static function blocked(
        string $reasonKey,
        string $reasonLabel,
        string $reasonGuidance,
        bool $isCompliance,
    ): self {
        return new self(
            drafted: false,
            blocked: true,
            unavailable: false,
            reasonKey: $reasonKey,
            reasonLabel: $reasonLabel,
            reasonGuidance: $reasonGuidance,
            isCompliance: $isCompliance,
        );
    }

    public static function unavailable(): self
    {
        return new self(drafted: false, blocked: false, unavailable: true);
    }

    /**
     * The block's details, for the step outcome's `params`.
     *
     * Keys and translation keys only — the run log composes its own sentence around them, in the
     * reader's language, from a row that may be weeks old.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        if (! $this->blocked) {
            return [];
        }

        return [
            'block_reason' => $this->reasonKey,
            'block_label' => $this->reasonLabel,
            'block_guidance' => $this->reasonGuidance,
            'is_compliance' => $this->isCompliance,
        ];
    }
}
