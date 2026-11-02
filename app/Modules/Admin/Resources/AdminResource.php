<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Core\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

/**
 * A declarative description of one model as the admin panel sees it (Phase 19a, ADR 0014).
 *
 * This is the class that makes "CRUD over every resource" a few hundred lines rather than forty
 * bespoke screens. A descriptor says what the columns are, which fields may be edited and under
 * what rules, what can be searched and filtered, and **which of create/update/delete is permitted
 * at all**; generic controllers and three Inertia pages do the rest.
 *
 * Three rules hold it in shape, and all three are enforced by tests rather than by good intentions:
 *
 * 1. **Descriptors are declarative.** They return data. Anything that needs to *decide* something
 *    needs a bespoke screen — see `TeamOperationsController` and `UserOperationsController` for
 *    what that looks like. The moment a descriptor grows a conditional, the registry has started
 *    becoming the form builder it exists to avoid.
 * 2. **Where a module owns the operation, the admin calls that module's Action.** The generic
 *    writer is for the records nobody wrote an Action for. Changing a plan, removing a member or
 *    erasing a data subject goes through `SwapPlanAction`, `RemoveTeamMemberAction` and
 *    `EraseSubjectDataAction` — a second write path would bypass the transaction, the cascade, the
 *    suppression entry and the audit row the product depends on.
 * 3. **Everything is off by default.** `allowsCreate`, `allowsUpdate` and `allowsDelete` all
 *    return false here. A resource is read-only until somebody deliberately says otherwise, and
 *    delete in particular is opt-in per resource because it is the one generic operation no Action
 *    is watching.
 *
 * Descriptors live in this namespace rather than in each module, and are listed in
 * `config/admin.php`. The inverse — every module declaring its own admin surface — would scatter an
 * operator-tooling concern across eighteen modules and make "what can an operator see and change?"
 * unanswerable without reading all of them. Admin is the one module whose job is to know
 * everything; what it knows is models and Actions, never another module's internals.
 */
abstract class AdminResource
{
    /**
     * @return class-string<Model>
     */
    abstract public function model(): string;

    /**
     * The URL segment and registry key — kebab-case plural, e.g. `discovery-runs`.
     */
    abstract public function key(): string;

    /**
     * Plural, for the index heading and the nav.
     */
    abstract public function label(): string;

    /**
     * Singular, for a record's own page and the confirmation copy.
     */
    abstract public function singular(): string;

    abstract public function group(): ResourceGroup;

    /**
     * @return list<ResourceColumn>
     */
    abstract public function columns(): array;

    /**
     * Columns shown on the record page but not in the index — the long text, the JSON blobs, the
     * things that make a table unreadable.
     *
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Columns the index's search box matches with a `LIKE`. Empty means no search box.
     *
     * @return list<string>
     */
    public function searchable(): array
    {
        return [];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Relations to eager load. A resource declaring a dotted column **must** declare its relation
     * here — the index renders a page of rows, so a lazy relation is an N+1 that
     * `preventLazyLoading` turns into a failure rather than a slow page.
     *
     * @return list<string>
     */
    public function with(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [$this->defaultSort()];
    }

    public function defaultSort(): string
    {
        return $this->newModel()->getKeyName();
    }

    public function defaultSortDirection(): string
    {
        return 'desc';
    }

    /**
     * The ability this resource needs, **on every route** — read and write alike.
     *
     * It is checked *in addition to* the route's own ability, so an operator needs both. That
     * matters because the route ability is necessarily generic: `tenant.write` is "fix a customer's
     * record", and a resource that is **not** a customer's record needs more than that.
     *
     * Two resources override it today, for different reasons:
     *
     * - `audit-logs` → {@see AdminAbility::ReadAudit}. It is other **operators'** actions as well
     *   as customers', which is a different permission even where today's roles carry both.
     * - `ai-prompt-templates` → {@see AdminAbility::ManagePlatformData}. The prompt registry is
     *   ours, not any tenant's, and editing it changes every customer's next AI call. Behind
     *   `tenant.write` alone, a support engineer could rewrite the system prompt for the whole
     *   platform from a screen meant for correcting a company name.
     *
     * Checked as a Gate in `ResourceController`, so authorisation stays in gates rather than in a
     * controller `if`.
     */
    public function ability(): AdminAbility
    {
        return AdminAbility::ReadTenantData;
    }

    public function allowsCreate(): bool
    {
        return false;
    }

    public function allowsUpdate(): bool
    {
        return false;
    }

    /**
     * Off by default, and deliberately separate from `allowsUpdate`. See rule 3 in the class note.
     */
    public function allowsDelete(): bool
    {
        return false;
    }

    /**
     * Whether rows belong to a tenant — i.e. the model carries `team_id`.
     *
     * Derived from the {@see BelongsToTeam} trait rather than declared, so a model that gains
     * tenancy later cannot be left behind in the panel. It decides two things: whether the index
     * offers a team filter, and which team id an audit entry for a read of it is stamped with.
     */
    public function isTenantOwned(): bool
    {
        return in_array(BelongsToTeam::class, class_uses_recursive($this->model()), true);
    }

    /**
     * The tenant a row belongs to, read off the attribute rather than a relation.
     *
     * Read off `team_id` rather than from {@see isTenantOwned()}'s trait check, because the two
     * genuinely differ: Cashier's `Subscription` and this product's `Notification` both carry
     * `team_id` without using the trait, and both are tenant data an audit entry has to be stamped
     * with. Attribute access rather than `$record->team_id` so an unhydrated model answers null
     * instead of throwing under `shouldBeStrict()`.
     */
    public function teamIdOf(Model $record): ?int
    {
        $value = $record->getAttributes()['team_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * A one-line human description of the record, for page titles and audit properties. Never
     * personal data: an audit entry about reading somebody's record must not be a copy of it.
     */
    public function title(Model $record): string
    {
        return $this->singular().' #'.$record->getKey();
    }

    public function table(): string
    {
        return $this->newModel()->getTable();
    }

    /**
     * A bare instance, for the metadata Eloquent only exposes on one: the table and the key name.
     */
    public function newModel(): Model
    {
        $model = $this->model();

        return new $model;
    }

    /**
     * The shape the Inertia pages render from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'singular' => $this->singular(),
            'group' => $this->group()->value,
            'group_label' => $this->group()->label(),
            'columns' => array_map(static fn (ResourceColumn $c): array => $c->toArray(), $this->columns()),
            'detail_columns' => array_map(static fn (ResourceColumn $c): array => $c->toArray(), $this->detailColumns()),
            'fields' => array_map(static fn (ResourceField $f): array => $f->toArray(), $this->fields()),
            'filters' => array_map(static fn (ResourceFilter $f): array => $f->toArray(), $this->filters()),
            'searchable' => $this->searchable() !== [],
            'sortable' => $this->sortable(),
            'default_sort' => $this->defaultSort(),
            'tenant_owned' => $this->isTenantOwned(),
            'can_create' => $this->allowsCreate(),
            'can_update' => $this->allowsUpdate(),
            'can_delete' => $this->allowsDelete(),
        ];
    }
}
