<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Resources\AdminResource;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The generic write path behind the registry (Phase 19a).
 *
 * It is deliberately small, and the smallness is the design. **Where a module owns an operation,
 * the admin calls that module's Action** — changing a plan is `SwapPlanAction`, removing a member is
 * `RemoveTeamMemberAction`, erasing a data subject is `EraseSubjectDataAction`. This writer is for
 * the records nobody wrote an Action for: a misspelled company name, a weight somebody typed as
 * 100, a watch that needs switching off. A second write path around the Actions would skip the
 * transaction, the cascade, the suppression entry and the audit row the product depends on, and the
 * phase's acceptance criterion is precisely that nothing in the panel does that.
 *
 * Three guarantees:
 *
 * - **Only declared fields are written.** The payload comes from `ResourceWriteRequest`, which
 *   builds its rules from the descriptor, so a field the descriptor does not declare cannot be set
 *   even if it is fillable on the model. The registry is a validated write path, not mass
 *   assignment with a Vue component on top.
 * - **Every write is in a transaction, and audited inside it**, stamped with the target tenant —
 *   see {@see AdminAudit} for why the team has to be passed explicitly here of all places.
 * - **Delete is refused unless the descriptor opted in.** The default is off. A delete is the one
 *   generic operation no Action is watching and no cascade was designed for.
 *
 * `forceFill` rather than `fill`, and that is not a loosening: the fields have already been
 * validated against the descriptor, and several of the columns an operator legitimately fixes
 * (`is_active`, `position`, `max_tokens`) are deliberately not in their model's `$fillable`, because
 * no tenant-facing request should be able to set them.
 */
final class ResourceWriter
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Already validated and cast by the FormRequest.
     */
    public function create(AdminResource $resource, array $attributes, ?int $teamId = null): Model
    {
        if (! $resource->allowsCreate()) {
            throw AdminException::readOnlyResource($resource->label());
        }

        return DB::transaction(fn (): Model => $this->inTenantContext($teamId, function () use ($resource, $attributes, $teamId): Model {
            $record = $resource->newModel();

            if ($teamId !== null && $resource->isTenantOwned()) {
                $record->setAttribute('team_id', $teamId);
            }

            $record->forceFill($attributes)->save();

            $this->audit->record(
                AdminAudit::RECORD_CREATED,
                $record,
                ['resource' => $resource->key(), 'fields' => array_keys($attributes)],
                $teamId ?? $resource->teamIdOf($record),
            );

            return $record;
        }));
    }

    /**
     * @param  array<string, mixed>  $attributes  Already validated and cast by the FormRequest.
     */
    public function update(AdminResource $resource, Model $record, array $attributes): Model
    {
        if (! $resource->allowsUpdate()) {
            throw AdminException::readOnlyResource($resource->label());
        }

        return DB::transaction(fn (): Model => $this->inTenantContext($resource->teamIdOf($record), function () use ($resource, $record, $attributes): Model {
            $record->forceFill($attributes);

            // The names of what changed, never the values. An audit entry about a correction to
            // somebody's record must not be a copy of the record — the before and after are in the
            // row's own history if the module keeps one.
            $changed = array_keys($record->getDirty());

            $record->save();

            $this->audit->record(
                AdminAudit::RECORD_UPDATED,
                $record,
                ['resource' => $resource->key(), 'changed' => $changed],
                $resource->teamIdOf($record),
            );

            return $record;
        }));
    }

    public function delete(AdminResource $resource, Model $record): void
    {
        if (! $resource->allowsDelete()) {
            throw AdminException::deleteNotPermitted($resource->label());
        }

        $teamId = $resource->teamIdOf($record);
        $key = $record->getKey();

        DB::transaction(fn () => $this->inTenantContext($teamId, function () use ($resource, $record, $teamId, $key): void {
            // Audited **before** the delete, so the entry exists even if the cascade fails and the
            // transaction rolls back — in which case the entry rolls back with it, which is correct.
            // Audited inside the transaction rather than after it for the same reason.
            $this->audit->record(
                AdminAudit::RECORD_DELETED,
                $record,
                ['resource' => $resource->key(), 'record_id' => $key],
                $teamId,
            );

            $record->delete();
        }));
    }

    /**
     * Run a write inside the tenant's own context, when there is one.
     *
     * All three writes do this, and uniformly on purpose. A model event fired by a save or a delete
     * — an observer, a cascade, a derived counter — runs with whatever team is active, and the
     * panel's is deliberately none: a tenant table queried from inside one of those would be
     * queried across **every** tenant. Nothing in the currently writable set does that, which is
     * exactly why the inconsistency would have gone unnoticed until something did.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenantContext(?int $teamId, callable $callback): mixed
    {
        if ($teamId === null) {
            return $callback();
        }

        return $this->teamContext->run($teamId, $callback);
    }
}
