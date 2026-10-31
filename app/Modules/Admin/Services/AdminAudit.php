<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Every audit entry the admin panel writes goes through here (Phase 19a).
 *
 * It exists for one subtle bug, which is worth stating plainly because it is invisible until
 * somebody needs the trail. {@see AuditLogger} stamps `team_id` from {@see TeamContext} — and the
 * admin panel has **deliberately cleared** that context, because clearing it is what lets an index
 * read across tenants at all. Call the logger directly from an admin controller and every entry
 * lands with `team_id = null`: the row exists, nothing errors, and the per-tenant audit view — the
 * one a customer's support ticket is answered from — shows nothing at all.
 *
 * So the target team is passed in explicitly and the write happens inside
 * {@see TeamContext::run()}, which both stamps the column and lets `BelongsToTeam`'s auto-assign do
 * its usual job. A platform-wide action passes none and is correctly recorded with no team.
 *
 * **Reads are audited too**, which is the other half. `AuditLog` conventionally records writes, but
 * an operator opening a tenant's prospect list is a privacy event: somebody outside that business
 * read their customers' details. The entry carries the resource, the filters and a result count —
 * ids and counts, never a name, an address or a row. A log of who looked at personal data must not
 * itself be a copy of it.
 */
final class AdminAudit
{
    public const string RESOURCE_VIEWED = 'admin.resource.viewed';

    public const string RECORD_VIEWED = 'admin.record.viewed';

    public const string RECORD_CREATED = 'admin.record.created';

    public const string RECORD_UPDATED = 'admin.record.updated';

    public const string RECORD_DELETED = 'admin.record.deleted';

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @param  int|null  $teamId  The tenant the action was *about*, not the operator's own. Null for
     *                            a platform-wide action, which is a real case and not a fallback.
     */
    public function record(string $action, ?Model $subject = null, array $properties = [], ?int $teamId = null): AuditLog
    {
        if ($teamId === null) {
            return $this->logger->log($action, $subject, $properties);
        }

        return $this->teamContext->run(
            $teamId,
            fn (): AuditLog => $this->logger->log($action, $subject, $properties),
        );
    }
}
