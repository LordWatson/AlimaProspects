<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\ICP\Models\Icp;
use App\Modules\Scoring\Support\SeniorityLadder;
use Spatie\LaravelData\Data;

/**
 * Which people at a company are worth paying to find (PL2).
 *
 * The point of narrowing it is money: a people-finder returns what it is asked for and bills for
 * it, so "everyone at Acme" is a bill for forty records to get the two a member would have
 * contacted. The criteria come from the team's own ICP, so a team that sells to operations
 * directors pays for operations directors.
 *
 * Titles and seniorities are **free text, not enums**, matching `IcpCriterion` — a team writes
 * "Head of Ops" because that is what the job is called at the companies they sell to, and
 * {@see SeniorityLadder} is what makes that comparable without a fixed vocabulary.
 */
final class PersonCriteria extends Data
{
    /**
     * @param  list<string>  $titles  job titles the team sells to
     * @param  list<string>  $seniorities  levels, as the team words them ("director or above")
     * @param  list<string>  $departments  departments, where the vendor can filter by one
     */
    public function __construct(
        public array $titles = [],
        public array $seniorities = [],
        public array $departments = [],
        /** How many people to pay for. The single most important field on this object. */
        public int $limit = 3,
    ) {}

    /**
     * The criteria implied by a team's ideal customer profile, capped at `$limit`.
     *
     * Reads the ICP's own `job_title` and `seniority` criteria and nothing else: an ICP's industry
     * and headcount rules are about *which companies*, and a people-finder has already been handed
     * the company.
     */
    public static function fromIcp(?Icp $icp, int $limit = 3): self
    {
        if (! $icp instanceof Icp) {
            return new self(limit: $limit);
        }

        $of = static fn (string $type): array => array_values(array_filter(array_map(
            static fn ($criterion): ?string => trim((string) $criterion->value) ?: null,
            $icp->criteria->where('type.value', $type)->all(),
        )));

        return new self(
            titles: $of('job_title'),
            seniorities: $of('seniority'),
            limit: $limit,
        );
    }

    /**
     * Whether anything narrows this at all. A people-finder asked with nothing to go on is a
     * request for whoever the vendor happens to list first, which is rarely a decision-maker — the
     * caller should fall back to the free-tier extraction rather than pay for that.
     */
    public function isNarrowed(): bool
    {
        return $this->titles !== [] || $this->seniorities !== [] || $this->departments !== [];
    }
}
