<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\AuditContext;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes immutable audit entries. Never log secrets or full personal data — pass IDs and
 * non-sensitive metadata only (CLAUDE.md logging rules).
 *
 * Ambient properties from {@see AuditContext} are merged in. That is how an impersonated session
 * records the operator behind the customer (Phase 19a) without every module's Action having to
 * know that impersonation exists — there is no layer between an Action and this logger to annotate
 * from. The caller's own keys always win; see that class.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $action, ?Model $subject = null, array $properties = [], ?User $actor = null): AuditLog
    {
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        // Ambient first, so a caller's own key of the same name overwrites it rather than the
        // other way round.
        $properties = [...$this->context->all(), ...$properties];

        return AuditLog::query()->create([
            'team_id' => $this->teamContext->id(),
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => Request::ip(),
            'request_id' => (string) Request::header('X-Request-Id', ''),
        ]);
    }
}
