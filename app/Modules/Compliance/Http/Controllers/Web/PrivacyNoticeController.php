<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hosted privacy notice for prospects (docs/compliance.md §3.7) — the page every outreach
 * footer links to.
 *
 * **Public, and it has to be.** The people it is written for are not users: they received a message
 * from a team using Alima and want to know who has their details and how to stop it. A notice
 * behind a login is not a notice. It is also bilingual, because it is the one page in the product a
 * UAE prospect is most likely to read (ADR 0007).
 *
 * **It names Alima, not the tenant.** The business that made contact is the controller for that
 * prospect's data and names itself in the footer of the message; Alima is the processor and this
 * page explains that split, what it holds on their behalf and the two things a reader actually
 * wants — the opt-out and how to make a request. A per-tenant notice would mean a URL that
 * discloses which team is looking at whom to anybody who follows it, and is post-MVP.
 *
 * The particulars come from `config/compliance.php` so counsel can change them without a
 * deployment touching code. ⚖️ The wording is part of the pre-launch legal review (ADR 0007).
 */
final class PrivacyNoticeController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('public/PrivacyNotice', [
            'notice' => [
                'version' => (string) config('compliance.privacy_notice.version', '1.0'),
                'updated_on' => (string) config('compliance.privacy_notice.updated_on', ''),
                'contact_email' => (string) config('compliance.privacy_notice.contact_email', ''),
                'hosting_location' => (string) config('compliance.privacy_notice.hosting_location', ''),
                // The retention defaults, so the notice states a real number rather than "a
                // reasonable period". A team may hold data for less; it may not hold it for longer
                // without changing a setting that is audited.
                'uncontacted_days' => (int) config('compliance.retention.defaults.uncontacted_lead_days', 180),
                'contacted_days' => (int) config('compliance.retention.defaults.contacted_lead_days', 540),
            ],
        ]);
    }
}
