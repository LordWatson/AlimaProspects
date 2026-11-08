<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

use App\Modules\Enrichment\Data\CompanyQuery;
use App\Modules\Enrichment\Data\PeopleResult;
use App\Modules\Enrichment\Data\PersonCriteria;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;

/**
 * A vendor that can say who the decision-makers at a company are (PL2).
 *
 * The capability that comes **first** in practice: the other three need a person to work on, and
 * for most leads the free-tier extraction found a role inbox and no name. It is also where the
 * money goes fastest, because a people-finder bills for what it returns — which is why
 * {@see PersonCriteria} carries a hard `limit` and the team's own ICP titles rather than asking for
 * everybody at the company.
 */
interface PersonFinder extends EnrichmentProvider
{
    /**
     * Find up to `$criteria->limit` people matching the criteria, or return a miss.
     *
     * A provider returning only unnamed records (a department, a role inbox) has returned a miss as
     * far as {@see PeopleResult::found()} is concerned: treating "Sales Team" as a hit would stop
     * the waterfall one provider short of an actual person.
     *
     * @throws EnrichmentProviderException when the provider itself failed
     */
    public function findPeople(CompanyQuery $company, PersonCriteria $criteria): PeopleResult;
}
