<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Controllers\Concerns;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The resolution every Outreach controller repeats: the caller, their team, and a team-scoped read
 * of whatever is being acted on.
 *
 * Reading inside {@see TeamContext} is what makes a cross-tenant request a **404 before any policy
 * runs** — the global scope hides the row, so the request can't even learn that it exists. The
 * policy still double-checks ownership afterwards (ADR 0006).
 *
 * {@see OutreachSuppression} is the exception and is resolved differently, because it deliberately
 * has no tenant global scope: a global entry must be visible to every team. There, the explicit
 * `scope_key` filter is the whole of the scoping, and the policy is the whole of the
 * authorisation — see {@see suppression()}.
 */
trait ResolvesOutreach
{
    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }

    /**
     * @param  list<string>  $with  Relations the policy or the response needs. `team` is always
     *                              loaded, because every policy reads it and a lazy read would
     *                              trip preventLazyLoading.
     */
    protected function lead(Request $request, int $leadId, array $with = []): Lead
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): Lead => Lead::query()
            ->where('team_id', $team->getKey())
            ->with(['team', ...$with])
            ->findOrFail($leadId));
    }

    /**
     * @param  list<string>  $with
     */
    protected function message(Request $request, int $messageId, array $with = []): OutreachMessage
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::query()
            ->where('team_id', $team->getKey())
            ->with(['team', ...$with])
            ->findOrFail($messageId));
    }

    protected function template(Request $request, int $templateId): OutreachTemplate
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): OutreachTemplate => OutreachTemplate::query()
            ->where('team_id', $team->getKey())
            ->with('team')
            ->findOrFail($templateId));
    }

    protected function attempt(Request $request, int $attemptId): ContactAttempt
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): ContactAttempt => ContactAttempt::query()
            ->where('team_id', $team->getKey())
            ->with(['lead.team', 'contact', 'message'])
            ->findOrFail($attemptId));
    }

    /**
     * A do-not-contact entry on the caller's own list, or a global one.
     *
     * The `whereIn` is deliberately explicit rather than relying on a global scope that this model
     * does not have: it is what stops a member reaching another team's list, and the only place
     * that scoping exists.
     */
    protected function suppression(Request $request, int $entryId): OutreachSuppression
    {
        $team = $this->team($request);

        return OutreachSuppression::query()
            ->whereIn('scope_key', [
                SuppressionScope::Global->keyFor(null),
                SuppressionScope::Team->keyFor((int) $team->getKey()),
            ])
            ->with('team')
            ->findOrFail($entryId);
    }

    /**
     * The team's contacts for a lead, for the draft screen's recipient picker. Ordered so the one
     * a member usually wants is first: a named person with an address, then a role mailbox, then
     * whatever is left.
     *
     * @return Collection<int, LeadContact>
     */
    protected function contactsFor(Lead $lead): Collection
    {
        return LeadContact::query()
            ->where('lead_id', $lead->getKey())
            ->orderByRaw('case when email is null or email = \'\' then 1 else 0 end')
            ->orderBy('is_role_based')
            ->orderBy('id')
            ->get();
    }

    /**
     * A transient, team-stamped model for authorising an ability that isn't about one row — adding
     * a brief, adding a suppression entry.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    protected function transient(string $model, Team $team, array $attributes = []): Model
    {
        $instance = new $model;
        $instance->forceFill(['team_id' => $team->getKey(), ...$attributes]);
        $instance->setRelation('team', $team);

        return $instance;
    }
}
