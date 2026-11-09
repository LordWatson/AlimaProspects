<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Services\AllowAllContactApproval;

/**
 * Whether it is lawful to go looking for, store, or reveal this person's details (PL3's seam,
 * PL5's job).
 *
 * The seam Compliance will bind. Enrichment deliberately knows nothing about suppression lists,
 * TPS registers or PECR subscriber types — `OutreachGuard` is the one answer to *may this prospect
 * be approached* (ADR 0024), and this is the narrower question in front of it: *may we hold this at
 * all*. Keeping them apart matters because they have different answers: a company we may not cold-
 * call is still a company whose switchboard we may know.
 *
 * It is asked at **two** moments, and the second is the one that costs money:
 *
 * 1. When a found person is about to become a contact — a person who asked to be left alone should
 *    not be stored (data minimisation, `docs/compliance.md` §3.9).
 * 2. When a member is about to **pay to reveal** a value. Charging for an address that may not be
 *    used is the worst of both: a bill and a dead end.
 *
 * The default ({@see AllowAllContactApproval}) is permissive,
 * which is the **status quo** rather than a new permission: the free-tier extraction has stored
 * contacts with no such check since Phase 8, and sending has always been gated separately by
 * `OutreachGuard`. PL5 binds the real one.
 */
interface ContactApprovalGate
{
    /**
     * Whether this email address may be held and shown for this team.
     */
    public function allowsEmail(Team $team, string $email): bool;

    /**
     * Whether this phone number may be held and shown for this team.
     */
    public function allowsPhone(Team $team, string $phone): bool;

    /**
     * Whether anything at this company's domain may be, which is the **company-level** question
     * (PL6).
     *
     * Added for the export, where the unit leaving the building is a company rather than a person:
     * "stop contacting anyone at this company" has to mean that a row for that company does not
     * end up in a spreadsheet on somebody's laptop, where no later suppression can reach it. The
     * email check already covers the domain for an address it is given — this is the same question
     * asked when there is no address to ask it about.
     */
    public function allowsDomain(Team $team, string $domain): bool;
}
