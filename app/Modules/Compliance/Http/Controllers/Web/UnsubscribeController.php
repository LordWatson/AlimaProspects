<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\RecordOptOutAction;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Outreach\Models\OutreachMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hosted one-click unsubscribe endpoint (docs/compliance.md §3.3) — RFC 8058's HTTPS target,
 * and the link in every outreach footer.
 *
 * **Public and signed.** The recipient is not a user, so there is no session to authenticate; the
 * signed URL is the authorisation, and it carries the message id and nothing else. It cannot be
 * guessed, reversed into an address, or incremented to find other recipients.
 *
 * **The GET does nothing.** It shows a page with a button. This is not politeness: mail clients,
 * link checkers and corporate security gateways fetch every URL in an email, and an endpoint that
 * acted on GET would unsubscribe people who never clicked — and then report success to a team
 * wondering why their list evaporated. RFC 8058 agrees: one-click is a **POST**.
 *
 * **The POST is exempt from CSRF** (see bootstrap/app.php). It has to be: a mail client posting
 * `List-Unsubscribe=One-Click` has no session and no token. The signature stands in its place, and
 * an attacker who holds the signed URL already holds the recipient's email.
 *
 * It is deliberately **idempotent and incurious**. A second click says the same thing as the first,
 * and no response reveals the address, the team or the company — a confirmation reading "you will
 * no longer hear from Acme Ltd" would turn a forwarded link into a disclosure.
 */
final class UnsubscribeController extends Controller
{
    public function show(Request $request, int $message): Response
    {
        // Resolved only to be sure the link points at something real. Nothing about the message is
        // put on the page.
        $model = $this->find($message);

        return Inertia::render('public/Unsubscribe', [
            'done' => false,
            'expired' => ! $model instanceof OutreachMessage,
            // The URL the form posts back to — this same signed URL, which the browser is already
            // on, so the signature survives the round trip.
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(int $message, RecordOptOutAction $action): RedirectResponse|Response
    {
        $model = $this->find($message);

        if (! $model instanceof OutreachMessage) {
            return $this->noLongerActive();
        }

        try {
            $action->handle($model);
        } catch (ComplianceException) {
            // There is nothing left to suppress, which in practice means the retention sweep has
            // already **anonymised** the lead: the contact is gone and `sent_to` was cleared, so
            // the message survives with no identity on it.
            //
            // Caught here rather than left to the central handler, which would turn it into a
            // flash toast and a `back()` — sending a mail client's one-click POST to `/`, and
            // showing a browser the button again. The person is told the link is dead and pointed
            // at the reply route, which is the honest answer: we no longer hold their address, so
            // there is nothing left to stop.
            return $this->noLongerActive();
        }

        return redirect()->route('unsubscribe.done');
    }

    /**
     * The "this link is no longer active" state, with the reply route offered instead.
     *
     * A 200 with a page rather than a redirect to the confirmation, because the confirmation says
     * "done" and this is not done — and a one-click POST needs a success status rather than a
     * bounce through a page that would claim something untrue.
     */
    private function noLongerActive(): Response
    {
        return Inertia::render('public/Unsubscribe', [
            'done' => false,
            'expired' => true,
            'action' => null,
        ]);
    }

    /**
     * The confirmation. Unsigned and parameterless on purpose: it says only that the request was
     * honoured, so it discloses nothing and can be reloaded freely.
     */
    public function done(): Response
    {
        return Inertia::render('public/Unsubscribe', [
            'done' => true,
            'expired' => false,
            'action' => null,
        ]);
    }

    /**
     * The message behind the link, or null when there isn't one any more.
     *
     * Read **without the tenant scope**, because there is no team in context — nobody is logged in.
     * The signed id is the authorisation. `team` and `contact` are eager-loaded because the Action
     * needs both and a lazy read would trip `preventLazyLoading`.
     */
    private function find(int $message): ?OutreachMessage
    {
        return OutreachMessage::query()
            ->withoutGlobalScopes()
            ->with(['team', 'contact'])
            ->find($message);
    }
}
