<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Support\Facades\Log;

/**
 * A team loads its own do-not-contact list in bulk (PL5).
 *
 * The thing a customer actually arrives with is a column out of their CRM: every client they
 * already serve, every competitor, every company somebody promised not to approach. Typing those
 * in one at a time is why a suppression list ends up empty.
 *
 * **It splits on separators rather than parsing a CSV**, which is the smaller and the better
 * answer. A member says what the values *are* — addresses, domains, numbers, company numbers — and
 * then every cell of whatever they paste or upload is read as one. That handles a one-column export
 * and a copied spreadsheet column with no mapping screen, no header detection and no second CSV
 * reader in the codebase; `LeadCsvImporter` stays Leads Workspace's, where its `company_name`
 * requirement makes sense.
 *
 * Three guarantees it keeps:
 *
 * - **Nothing raw is stored.** {@see SuppressionList} hashes every value and keeps a mask. The
 *   uploaded text is never written anywhere, which is also why there is no "review your import"
 *   step holding it in a session.
 * - **Unusable values are reported, not stored.** A half-remembered address hashed and stored is an
 *   entry that never matches anything while the team believes they are covered, so the count comes
 *   back and the screen says so.
 * - **Team scope and `manual` only.** The same rule {@see SuppressIdentityAction} keeps: a member
 *   cannot write a global entry from the product, because a global entry binds every other tenant.
 *
 * Bounded by `outreach.suppressions.max_import`, and the cap is reported rather than silently
 * applied — an import that stopped at a thousand of five thousand would leave four thousand
 * companies contactable and nobody told.
 */
final class ImportSuppressionsAction
{
    public function __construct(
        private readonly SuppressionList $suppressions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{added: int, duplicate: int, skipped: int, truncated: bool}
     */
    public function handle(
        Team $team,
        User $member,
        SuppressionType $type,
        string $values,
        ?string $note = null,
    ): array {
        $max = max(1, (int) config('outreach.suppressions.max_import', 2_000));
        $candidates = $this->split($values);

        $truncated = count($candidates) > $max;
        $candidates = array_slice($candidates, 0, $max);

        $added = 0;
        $duplicate = 0;
        $skipped = 0;

        foreach ($candidates as $value) {
            $entry = $this->suppressions->suppress(
                type: $type,
                value: $value,
                scope: SuppressionScope::Team,
                reason: SuppressionReason::Manual,
                teamId: (int) $team->getKey(),
                actor: $member,
                note: $note,
            );

            if ($entry === null) {
                $skipped++;

                continue;
            }

            if ($entry->wasRecentlyCreated) {
                $added++;
            } else {
                $duplicate++;
            }
        }

        // Counts and the type only. The list itself is the team's own data about third parties and
        // has no business in an audit payload, which is the same rule `SuppressionList` keeps for a
        // single entry.
        $this->audit->log('outreach.suppressions_imported', null, [
            'type' => $type->value,
            'added' => $added,
            'duplicate' => $duplicate,
            'skipped' => $skipped,
            'truncated' => $truncated,
        ], $member);

        Log::channel('outreach')->info('Suppression list imported.', [
            'team_id' => (int) $team->getKey(),
            'type' => $type->value,
            'added' => $added,
            'skipped' => $skipped,
        ]);

        return ['added' => $added, 'duplicate' => $duplicate, 'skipped' => $skipped, 'truncated' => $truncated];
    }

    /**
     * Every cell of the pasted or uploaded text, de-duplicated, in the order it arrived.
     *
     * Newlines, commas, semicolons and tabs are all separators, because the four of them are what
     * the files people actually have use — and quoted fields are not honoured on purpose: a quote
     * around an email address is noise, and treating it as CSV syntax would be the start of writing
     * a CSV parser.
     *
     * @return list<string>
     */
    private function split(string $values): array
    {
        $parts = preg_split('/[\r\n,;\t]+/', $values) ?: [];

        $cleaned = [];
        $seen = [];

        foreach ($parts as $part) {
            // Strip the quotes a spreadsheet export wraps cells in, then whitespace.
            $part = trim(trim($part), "\"'");

            // The seen-set is kept beside the result rather than being it: a phone number used as
            // an array key becomes an integer, and `array_keys()` would hand back a list of ints.
            if ($part !== '' && ! isset($seen[$part])) {
                $seen[$part] = true;
                $cleaned[] = $part;
            }
        }

        return $cleaned;
    }
}
