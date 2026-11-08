<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Enums;

use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Services\LeadConfidence;

/**
 * What sort of fact an item of {@see LeadEvidence} is (PL1).
 *
 * **This is an enum and the row's `source` beside it is not**, which looks inconsistent until you
 * apply ADR 0018 §1's test — *does the application branch on the value?* The kind decides the
 * half-life its decay is measured against, and only {@see self::Signal} counts towards
 * `leads.timing_score`, so {@see LeadConfidence} branches on it in two places. Nothing branches on
 * *which provider* said a thing: the weight is a lookup in `config/evidence.php` and an unknown key
 * is legal. A source is therefore data, and a kind is a type.
 *
 * Five cases, and the distinction that matters most is the last two: {@see self::RegisterRecord} is
 * something a company filed, and {@see self::AiInference} is something a model thought. Both are
 * useful; only one of them may be repeated to a prospect.
 */
enum EvidenceKind: string
{
    /** A trigger event — a filing, an announcement, a news story. The only kind that times. */
    case Signal = 'signal';

    /** A fact about the company as it stands: sector, size, location, technologies. */
    case Firmographic = 'firmographic';

    /** Something read off the company's own site or the open web. */
    case WebResearch = 'web_research';

    /** The model's own reading, rather than a document. Always the weakest reason on a lead. */
    case AiInference = 'ai_inference';

    /** A statutory or official record: Companies House, a gazette notice, a register entry. */
    case RegisterRecord = 'register_record';

    public function label(): string
    {
        return match ($this) {
            self::Signal => 'Trigger event',
            self::Firmographic => 'Company profile',
            self::WebResearch => 'Web research',
            self::AiInference => 'AI inference',
            self::RegisterRecord => 'Official record',
        };
    }

    /**
     * Whether an item of this kind is a reason to act **now** rather than a fact about the company.
     * Only these feed `leads.timing_score`: a company's sector is as true next quarter as it is
     * today, so counting it as timing would make every enriched lead look urgent.
     */
    public function isTiming(): bool
    {
        return $this === self::Signal;
    }

    /**
     * Days for this kind's evidence to lose half its weight, from `config/evidence.php`. Falls back
     * to the shortest sensible window rather than to "never decays": a kind with no configured
     * half-life is a mistake, and the safe direction for a mistake is to under-claim.
     */
    public function halfLifeDays(): int
    {
        /** @var array<string, int> $halfLives */
        $halfLives = (array) config('evidence.recency_half_life_days', []);

        return max(1, (int) ($halfLives[$this->value] ?? 30));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
