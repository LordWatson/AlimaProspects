<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Exceptions\EnrichmentException;
use App\Modules\Enrichment\Models\LeadContact;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A member spends to see a contact detail a vendor was paid for (PL3; metered in PL4).
 *
 * Short, and every line of it is a guard:
 *
 * - **Already revealed is a no-op, not a second charge.** A double-click, a retried request and a
 *   second member opening the same lead must all cost once. The stamp is the idempotency key, which
 *   is why `revealed_email_at` is set inside the same transaction that reserves.
 * - **A value that needs no reveal is refused.** An address the free-tier extraction read off a
 *   public page is not for sale (ADR 0029), and taking money for it would be taking money for
 *   something already on the screen.
 * - **The approval gate is asked before the charge, not after.** Charging for an address a team may
 *   not lawfully use is the worst of both outcomes: a bill and a dead end. PL5 binds the real gate;
 *   until then this is the status quo.
 * - **The reveal is audited.** It is a member spending team money to see a person's contact
 *   details — both halves of that are things somebody will later ask about.
 *
 * A reveal is a **separate act from finding**: the finding already happened and was already paid
 * for at the vendor. What is metered here is the team's allowance, which is why this consults
 * {@see CreditReserver} rather than a provider.
 */
final class RevealContactValueAction
{
    public function __construct(
        private readonly CreditReserver $reserver,
        private readonly ContactApprovalGate $approval,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Reveal the contact's email, returning it. Idempotent.
     *
     * @throws EnrichmentException when there is nothing to reveal, it is not for sale, the team may
     *                             not use it, or the allowance is spent
     */
    public function email(Team $team, LeadContact $contact, User $actor): string
    {
        $email = $contact->email;

        if ($email === null) {
            throw EnrichmentException::nothingToReveal();
        }

        if (! $contact->requiresEmailReveal()) {
            // Either already revealed, or never masked in the first place. Both are "here it is",
            // and neither is a charge.
            return $email;
        }

        if (! $this->approval->allowsEmail($team, $email)) {
            throw EnrichmentException::revealNotPermitted();
        }

        $this->spend($team, EnrichmentScope::Email, function () use ($contact): void {
            $contact->forceFill(['revealed_email_at' => Carbon::now()])->save();
        });

        $this->audit->log('enrichment.email_revealed', $contact, [
            'lead_id' => (int) $contact->lead_id,
            // The address itself is never in the trail — the trail records that a reveal happened,
            // not a second copy of a prospect's personal data (CLAUDE.md logging rules).
            'contact_id' => (int) $contact->getKey(),
        ], $actor);

        return $email;
    }

    /**
     * Reveal the contact's phone number, returning it. Idempotent.
     *
     * @throws EnrichmentException
     */
    public function phone(Team $team, LeadContact $contact, User $actor): string
    {
        $number = $contact->phoneValue();

        if ($number === null) {
            throw EnrichmentException::nothingToReveal();
        }

        if (! $contact->requiresPhoneReveal()) {
            return $number;
        }

        if (! $this->approval->allowsPhone($team, $number)) {
            throw EnrichmentException::revealNotPermitted();
        }

        $this->spend($team, EnrichmentScope::Phone, function () use ($contact): void {
            $contact->forceFill(['revealed_phone_at' => Carbon::now()])->save();
        });

        $this->audit->log('enrichment.phone_revealed', $contact, [
            'lead_id' => (int) $contact->lead_id,
            'contact_id' => (int) $contact->getKey(),
        ], $actor);

        return $number;
    }

    /**
     * @param  Closure(): void  $stamp
     *
     * Reserve one unit, stamp the reveal, and commit — all inside one transaction.
     *
     * The stamp and the charge have to be atomic. If the stamp were outside, two concurrent clicks
     * would both reserve and both charge; if the reserve were outside, a failed stamp would leave
     * an allowance spent on nothing.
     */
    private function spend(Team $team, EnrichmentScope $scope, Closure $stamp): void
    {
        $reservation = $this->reserver->reserve($team, $scope, 1);

        if (! $reservation->granted()) {
            throw EnrichmentException::allowanceSpent($reservation->refusalCode);
        }

        try {
            // Wrapped in a closure with a concrete return rather than handed `$stamp` straight to
            // `transaction()`: a void callback leaves the helper's return type unresolvable, and
            // the wrapper costs nothing.
            DB::transaction(static function () use ($stamp): bool {
                $stamp();

                return true;
            });
        } catch (Throwable $e) {
            $this->reserver->release($reservation);

            throw $e;
        }

        // A reveal has no vendor cost of its own: the finding was paid for when it happened. What
        // is settled here is the team's allowance, one unit of it.
        $this->reserver->commit($reservation, spentMicros: 0, unitsUsed: 1);
    }
}
