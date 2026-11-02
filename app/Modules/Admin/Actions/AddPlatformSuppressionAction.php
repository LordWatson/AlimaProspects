<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Support\Facades\DB;

/**
 * Add an address, domain or number to the **global** do-not-contact list (Phase 19c).
 *
 * An address nobody on the platform may contact — a regulator, a journalist who asked once, a
 * company that complained to us rather than to the tenant approaching them.
 *
 * ## It calls Outreach's own service, and that is the whole design
 *
 * `SuppressionList::suppress()` is the only thing that writes `outreach_suppressions`: it
 * normalises the value, hashes it, builds the `scope_key` the unique index depends on, stores a
 * mask rather than the value, and writes its own audit entry. Admin adding a row itself would
 * bypass every one of those and produce an entry that matched nothing — a suppression list that
 * silently does not suppress.
 *
 * It is also why there is nothing to enforce here. A `global` entry has a null `team_id` by
 * construction, and {@see SuppressionList} includes the global scope in every send-time check for
 * every tenant, so {@see OutreachGuard} — still the only thing that answers whether a prospect may
 * be approached — refuses the send in every tenant from the moment this returns. The platform list
 * is not a second mechanism; it is the same table, used at its other scope.
 *
 * ## No lift, by design
 *
 * There is no counterpart to this Action and no route for one. `OutreachSuppression::isRemovable()`
 * refuses a global entry, the policy refuses one, and the panel does not offer one. Removing a
 * global suppression would overrule a person's stated wish with a button.
 */
final class AddPlatformSuppressionAction
{
    public function __construct(
        private readonly SuppressionList $suppressions,
        private readonly AdminAudit $audit,
    ) {}

    public function handle(
        SuppressionType $type,
        string $value,
        SuppressionReason $reason,
        User $operator,
        ?string $note = null,
    ): OutreachSuppression {
        // One transaction over both writes. `SuppressionList::suppress()` opens its own, and a
        // nested one joins it — so the entry and the operator's audit row either both land or
        // neither does. Without it a failure between them leaves every tenant blocked from
        // contacting somebody with no record of who decided that.
        return DB::transaction(fn (): OutreachSuppression => $this->write(
            $type,
            $value,
            $reason,
            $operator,
            $note,
        ));
    }

    private function write(
        SuppressionType $type,
        string $value,
        SuppressionReason $reason,
        User $operator,
        ?string $note,
    ): OutreachSuppression {
        $entry = $this->suppressions->suppress(
            type: $type,
            value: $value,
            scope: SuppressionScope::Global,
            reason: $reason,
            // Null, and the `global` scope is what makes that correct: the entry belongs to the
            // platform, not to whichever tenant's ticket prompted it.
            teamId: null,
            actor: $operator,
            note: $note,
        );

        if (! $entry instanceof OutreachSuppression) {
            // `suppress()` returns null for a value that is not a usable identity of that type —
            // it refuses to store a hash of nonsense, which is right and silent. This is what the
            // operator sees instead.
            throw AdminException::suppressionValueUnusable($type->label());
        }

        // A **second** entry, on top of the `outreach.suppressed` one the service writes. Not
        // duplication: that one records that an identity was suppressed, this one records that a
        // platform operator did it to every tenant at once, and the audit viewer's operator
        // categories are built on the `admin.` prefix. The masked preview, never the value.
        $this->audit->record(AdminAudit::PLATFORM_SUPPRESSED, $entry, [
            'type' => $type->value,
            'reason' => $reason->value,
            'value_preview' => $entry->value_preview,
            'already_present' => ! $entry->wasRecentlyCreated,
        ]);

        return $entry;
    }
}
