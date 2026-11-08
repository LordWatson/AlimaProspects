<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Actions\RequestBulkEnrichmentAction;
use App\Modules\Enrichment\Actions\RequestLeadEnrichmentAction;
use App\Modules\Enrichment\Actions\RevealContactValueAction;
use App\Modules\Enrichment\Http\Requests\RequestEnrichmentRequest;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Asking for enrichment, and spending to see what it found (PL3).
 *
 * Thin: resolve inside the team, authorise, one Action, redirect back with a toast. The result
 * arrives over Reverb (`LeadEnrichmentUpdated`), which is why none of these returns data — a
 * request that waited for a waterfall would be a request that timed out.
 */
final class EnrichmentController extends Controller
{
    /**
     * Ask for one lead to be enriched.
     */
    public function store(RequestEnrichmentRequest $request, int $lead, RequestLeadEnrichmentAction $enrich): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->lead($team, $lead);

        $this->authorize('work', $model);

        $run = $enrich->handle($team, $model, $request->scope(), $user);

        Inertia::flash('toast', $this->toastFor($run));

        return back();
    }

    /**
     * Ask for a selection of leads to be enriched, from the leads list.
     */
    public function bulk(RequestEnrichmentRequest $request, RequestBulkEnrichmentAction $enrich): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('work', $this->transientLead($team));

        $runs = $enrich->handle($team, $request->leadIds(), $request->scope(), $user);

        Inertia::flash('toast', [
            'type' => $runs === [] ? 'info' : 'success',
            'message' => $runs === []
                // The honest message. A member who selected forty leads that were all enriched
                // last week should be told that, not shown a success toast over nothing.
                ? __('Nothing to look for — those leads were enriched recently, or have no contacts yet.')
                : __('Alima is looking at :count lead(s). Results arrive as they come in.', ['count' => count($runs)]),
        ]);

        return back();
    }

    /**
     * Spend to see an address.
     */
    public function revealEmail(Request $request, int $lead, int $contact, RevealContactValueAction $reveal): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->contact($team, $lead, $contact);

        $this->authorize('reveal', $model);

        $reveal->email($team, $model, $user);

        // No value in the flash: it is on the contact, the page re-renders from the model, and a
        // revealed address in a session payload is a revealed address in a session store.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Here it is.')]);

        return back();
    }

    /**
     * Spend to see a number.
     */
    public function revealPhone(Request $request, int $lead, int $contact, RevealContactValueAction $reveal): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->contact($team, $lead, $contact);

        $this->authorize('reveal', $model);

        $reveal->phone($team, $model, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Here it is.')]);

        return back();
    }

    /**
     * What to tell the member about the run they just asked for.
     *
     * A refusal gets its own message, by **code**: "nothing to look for" and "you've used this
     * month's allowance" are different things to do about it, and a single "couldn't run" would
     * leave a member guessing which.
     *
     * @return array<string, string>
     */
    private function toastFor(?EnrichmentRun $run): array
    {
        if (! $run instanceof EnrichmentRun) {
            return ['type' => 'info', 'message' => __('Contact enrichment isn’t switched on for your team yet.')];
        }

        return match ($run->error_code) {
            null => ['type' => 'success', 'message' => __('Alima is looking. Results appear here as they arrive.')],
            'enrichment.no_contacts' => ['type' => 'info', 'message' => __('Find the decision-makers first, then Alima can look for their details.')],
            'enrichment.recently_enriched' => ['type' => 'info', 'message' => __('Alima looked at this lead recently. Nothing new to find yet.')],
            default => ['type' => 'info', 'message' => __('Alima didn’t need to look this time.')],
        };
    }

    private function lead(Team $team, int $leadId): Lead
    {
        return Lead::query()
            ->where('team_id', $team->getKey())
            ->with(['team', 'contacts'])
            ->findOrFail($leadId);
    }

    /**
     * The contact, resolved **through its lead** — so an id from another lead is a 404 rather than
     * a reveal landing on the wrong company's contact.
     */
    private function contact(Team $team, int $leadId, int $contactId): LeadContact
    {
        return $this->lead($team, $leadId)
            ->contacts()
            ->with('team')
            ->whereKey($contactId)
            ->firstOrFail();
    }

    private function transientLead(Team $team): Lead
    {
        $lead = (new Lead)->forceFill(['team_id' => $team->getKey()]);
        $lead->setRelation('team', $team);

        return $lead;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }
}
