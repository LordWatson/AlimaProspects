<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\SubmitSubjectRequestAction;
use App\Modules\Compliance\Actions\VerifySubjectRequestAction;
use App\Modules\Compliance\Enums\SubjectRequestType;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Compliance\Http\Requests\SubmitSubjectRequestRequest;
use App\Modules\Compliance\Models\DataSubjectRequest;
use App\Modules\Core\Support\Security\Turnstile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public privacy-request form (PL5, `docs/compliance.md` section 3.17) — `/privacy/request`.
 *
 * The product already honoured a request made **to a tenant**: a member searches, reviews and
 * erases (`compliance.dsar.*`, Phase 15). This is the other door, and the one a person who has
 * never heard of Alima can actually use. They found a link in an email footer, they have no
 * account, and a form behind a login is not an opt-out.
 *
 * **Four guards, and none of them is a login:**
 *
 * 1. The **throttle**, per IP, in the module provider. There is no account to key on.
 * 2. The **challenge** (Turnstile), when a key is configured. Off by default, and off means pass —
 *   an unreachable opt-out form is a worse compliance outcome than a spammable one
 *   ({@see Turnstile}).
 * 3. The **verification email**, which is the real authentication. Nothing is acted on until the
 *   person clicks a link in their own inbox, because an unverified form is a way to erase a
 *   competitor's prospects by typing their address into it.
 * 4. **Incuriousness.** The confirmation says only that we have written to them; it never says
 *   whether we hold them, and never names a team. "We have your record at Acme Ltd" would turn a
 *   form anybody can submit into a lookup tool.
 *
 * The verification link is **signed and temporary** and its route is `GET`, which is the one
 * deviation from the rule {@see UnsubscribeController} explains at length — a mail client that
 * prefetches it confirms a request the recipient did make, which is the outcome they asked for,
 * whereas a prefetched unsubscribe opts out somebody who never clicked. The Action is idempotent
 * either way.
 */
final class SubjectRequestController extends Controller
{
    public function create(Turnstile $turnstile): Response
    {
        return Inertia::render('public/PrivacyRequest', [
            'types' => array_map(static fn (SubjectRequestType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], SubjectRequestType::cases()),
            // The public site key, so the widget can render. Null when no challenge is configured,
            // and the form simply has none.
            'turnstileSiteKey' => $turnstile->siteKey(),
            'contactEmail' => config('compliance.privacy_notice.contact_email'),
            'submitted' => false,
            'verified' => false,
        ]);
    }

    public function store(
        SubmitSubjectRequestRequest $request,
        SubmitSubjectRequestAction $submit,
        Turnstile $turnstile,
    ): RedirectResponse {
        if (! $turnstile->verify($request->challengeToken(), $request->ip())) {
            throw ComplianceException::subjectRequestChallengeFailed();
        }

        $submit->handle($request->toData(), $request->ip());

        // Redirect rather than render, so a refresh does not resubmit — and to the one unsigned,
        // parameterless page, which discloses nothing and can be reloaded freely.
        return redirect()->route('privacy.request.sent');
    }

    /**
     * "Check your inbox." Says nothing about whether we hold the person, by design.
     */
    public function sent(): Response
    {
        return Inertia::render('public/PrivacyRequest', [
            'types' => [],
            'turnstileSiteKey' => null,
            'contactEmail' => config('compliance.privacy_notice.contact_email'),
            'submitted' => true,
            'verified' => false,
        ]);
    }

    /**
     * The signed link. Read **without the tenant scope** because there is no team in context and
     * `data_subject_requests` has no `team_id` at all — it is platform data, and the signature is
     * the authorisation.
     */
    public function verify(int $request, VerifySubjectRequestAction $verify): Response
    {
        $model = DataSubjectRequest::query()->find($request);

        if ($model instanceof DataSubjectRequest) {
            $verify->handle($model);
        }

        // The same page whether or not the row was found. A link pointing at a request that has
        // since been swept must not say so: the honest and the safe answer are the same one here,
        // because the person is told what will happen and nothing about what we hold.
        return Inertia::render('public/PrivacyRequest', [
            'types' => [],
            'turnstileSiteKey' => null,
            'contactEmail' => config('compliance.privacy_notice.contact_email'),
            'submitted' => false,
            'verified' => true,
        ]);
    }
}
