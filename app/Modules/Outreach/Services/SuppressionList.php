<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Services;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachSuppression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The do-not-contact list (docs/compliance.md §3.2–3.3). Everything that writes to or reads from
 * `outreach_suppressions` goes through here, so the normalisation, the hashing and the two scopes
 * have exactly one implementation.
 *
 * Three decisions worth knowing about:
 *
 * - **Only hashes are matched or stored.** A list of people who want nothing to do with us is not
 *   somewhere to keep their addresses in the clear. The hash is deterministic (unsalted) because
 *   recognising the same address arriving later from a different provider is the entire function.
 * - **An email check also checks its domain.** "Stop contacting anyone at this company" has to mean
 *   that, so `isEmailSuppressed()` tests the address *and* the domain in one indexed query.
 * - **A global entry is never hidden by tenancy.** The model deliberately has no team global scope
 *   (see {@see OutreachSuppression}); the queries here scope by *scope*, which is what makes one
 *   team's opt-out bind every other team.
 */
final class SuppressionList
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Whether this address — or anything at its domain — may not be contacted by this team.
     *
     * The team id is required rather than read from the ambient context: this is the check a send
     * depends on, and resolving it from a context that a queued job forgot to set would silently
     * downgrade it to "global only".
     */
    public function isEmailSuppressed(string $email, int $teamId): bool
    {
        $address = SuppressionType::Email->normalise($email);

        if ($address === null) {
            // Not a usable address, so there is nothing to send to in any case. Reported as
            // suppressed so a malformed address can never be treated as a clear one.
            return true;
        }

        $candidates = [[SuppressionType::Email, $address]];

        $domain = SuppressionType::Domain->normalise($address);

        if ($domain !== null) {
            $candidates[] = [SuppressionType::Domain, $domain];
        }

        return $this->anySuppressed($candidates, $teamId);
    }

    public function isPhoneSuppressed(string $phone, int $teamId): bool
    {
        $number = SuppressionType::Phone->normalise($phone);

        if ($number === null) {
            return true;
        }

        return $this->anySuppressed([[SuppressionType::Phone, $number]], $teamId);
    }

    public function isDomainSuppressed(string $domain, int $teamId): bool
    {
        $normalised = SuppressionType::Domain->normalise($domain);

        if ($normalised === null) {
            return false; // Not a domain at all — nothing to judge, and nothing to stop.
        }

        return $this->anySuppressed([[SuppressionType::Domain, $normalised]], $teamId);
    }

    /**
     * Add an entry, or return the one already there.
     *
     * Idempotent by construction: the unique index is on (scope, type, hash), and a repeat is the
     * normal case — the same person replying "stop" to two teams, a bounce recorded twice. Returns
     * null when the value isn't a usable identity of that type, which is not an error: a member
     * typing a half-remembered address into the list should be told, not have it stored as a hash
     * of nonsense that will never match anything.
     */
    public function suppress(
        SuppressionType $type,
        string $value,
        SuppressionScope $scope,
        SuppressionReason $reason,
        ?int $teamId = null,
        ?User $actor = null,
        ?string $note = null,
    ): ?OutreachSuppression {
        $normalised = $type->normalise($value);

        if ($normalised === null) {
            return null;
        }

        // A team entry without a team would be written as `team:` and shared by every team that
        // made the same mistake — the one way this table could leak between tenants.
        if ($scope === SuppressionScope::Team && $teamId === null) {
            throw new \InvalidArgumentException('A team-scoped suppression needs a team id.');
        }

        $entry = DB::transaction(fn (): OutreachSuppression => OutreachSuppression::query()->firstOrCreate(
            [
                'scope_key' => $scope->keyFor($teamId),
                'type' => $type->value,
                'value_hash' => $this->hash($normalised),
            ],
            [
                // Null on a global entry: it belongs to the platform, not to whoever happened to
                // record it.
                'team_id' => $scope === SuppressionScope::Team ? $teamId : null,
                'created_by' => $actor?->getKey(),
                'scope' => $scope->value,
                'value_preview' => $type->preview($normalised),
                'reason' => $reason->value,
                'note' => $note,
                'suppressed_at' => now(),
            ],
        ));

        if ($entry->wasRecentlyCreated) {
            // Ids, enums and the masked preview only — never the address (CLAUDE.md logging rules).
            $this->audit->log('outreach.suppressed', $entry, [
                'scope' => $scope->value,
                'type' => $type->value,
                'reason' => $reason->value,
                'value_preview' => $entry->value_preview,
            ], $actor);

            Log::channel('outreach')->info('Identity suppressed.', [
                'team_id' => $teamId,
                'scope' => $scope->value,
                'type' => $type->value,
                'reason' => $reason->value,
            ]);
        }

        return $entry;
    }

    /**
     * Lift a team's own entry. Global entries are refused — the model says so and the policy says
     * so, and this is the third place, because the one thing that must never happen here is a
     * tenant removing somebody's opt-out.
     */
    public function lift(OutreachSuppression $entry, ?User $actor = null): bool
    {
        if (! $entry->isRemovable()) {
            return false;
        }

        DB::transaction(static fn () => $entry->delete());

        $this->audit->log('outreach.suppression_lifted', $entry, [
            'scope' => $entry->scope->value,
            'type' => $entry->type->value,
            'value_preview' => $entry->value_preview,
        ], $actor);

        return true;
    }

    /**
     * A team's list: its own entries, plus every global one, newest first. The global entries are
     * included because a team puzzled about why a send was refused needs to see that the answer is
     * "they opted out", and the preview is all it shows them.
     *
     * @return Collection<int, OutreachSuppression>
     */
    public function forTeam(Team $team): Collection
    {
        return $this->listQuery($team)->get();
    }

    /**
     * The same list, one page at a time — what `/api/v1` serves (Phase 17). The table only ever
     * grows: every opt-out, every erasure and every global entry stays on it for as long as the
     * promise it records has to hold, so a phone asking for "the do-not-contact list" cannot be
     * handed all of it.
     *
     * @return LengthAwarePaginator<int, OutreachSuppression>
     */
    public function paginateForTeam(Team $team, int $perPage = 25): LengthAwarePaginator
    {
        return $this->listQuery($team)->with('team')->paginate($perPage);
    }

    /**
     * @return Builder<OutreachSuppression>
     */
    private function listQuery(Team $team): Builder
    {
        return OutreachSuppression::query()
            ->where(function (Builder $query) use ($team): void {
                $query->where('scope_key', SuppressionScope::Team->keyFor((int) $team->getKey()))
                    ->orWhere('scope_key', SuppressionScope::Global->keyFor(null));
            })
            ->orderByDesc('suppressed_at')
            ->orderByDesc('id');
    }

    /**
     * The deterministic lookup key. SHA-256 over the normalised value, so the same identity hashes
     * the same way across teams and across time.
     */
    public function hash(string $normalised): string
    {
        return hash('sha256', $normalised);
    }

    /**
     * Whether any of the (type, normalised value) candidates is suppressed for this team, globally
     * or on the team's own list — in one query, so the send-time check is a single round trip
     * however many candidates it has.
     *
     * @param  list<array{SuppressionType, string}>  $candidates
     */
    private function anySuppressed(array $candidates, int $teamId): bool
    {
        if ($candidates === []) {
            return false;
        }

        $scopeKeys = [
            SuppressionScope::Global->keyFor(null),
            SuppressionScope::Team->keyFor($teamId),
        ];

        return OutreachSuppression::query()
            ->whereIn('scope_key', $scopeKeys)
            ->where(function ($query) use ($candidates): void {
                foreach ($candidates as [$type, $value]) {
                    $query->orWhere(function ($inner) use ($type, $value): void {
                        $inner->where('type', $type->value)->where('value_hash', $this->hash($value));
                    });
                }
            })
            ->exists();
    }
}
