<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Core\Models\AuditLog;

/**
 * The cross-tenant audit trail, including this panel's own entries — an operator's reads of
 * tenant data and every impersonation session's start and stop.
 *
 * Read-only, and the descriptor forbids both update and delete, which is the only reason
 * the table is worth anything: an audit trail an operator can edit is a record of what
 * somebody was willing to leave behind. Append-only in the product, append-only here.
 *
 * Behind `audit.read` rather than `tenant.read`, because reading it means reading other
 * operators' actions as well as customers'.
 */
final class AuditLogResource extends AdminResource
{
    public function model(): string
    {
        return AuditLog::class;
    }

    public function key(): string
    {
        return 'audit-logs';
    }

    public function label(): string
    {
        return 'Audit log';
    }

    public function singular(): string
    {
        return 'Audit entry';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Platform;
    }

    public function ability(): AdminAbility
    {
        return AdminAbility::ReadAudit;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('action', 'Action'),
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::count('user_id', 'Actor'),
            ResourceColumn::text('subject_type', 'Subject'),
            ResourceColumn::count('subject_id', 'Subject id'),
            ResourceColumn::text('ip_address', 'IP'),
            ResourceColumn::date('created_at', 'When'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('properties', 'Properties'),
            ResourceColumn::text('request_id', 'Request id'),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'action',
            'subject_type',
            'request_id',
        ];
    }

    /**
     * @return list<string>
     */
    public function with(): array
    {
        return [
            'team',
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [
            'id',
            'created_at',
            'action',
        ];
    }
}
