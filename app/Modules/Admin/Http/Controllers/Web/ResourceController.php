<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Http\Requests\ResourceIndexRequest;
use App\Modules\Admin\Http\Requests\ResourceWriteRequest;
use App\Modules\Admin\Resources\AdminResource;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\ResourceQuery;
use App\Modules\Admin\Services\ResourceWriter;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One controller for every resource in the registry (Phase 19a).
 *
 * Thin in the way CLAUDE.md §2 asks, just with the descriptor standing in for the usual per-screen
 * knowledge: authorise (the middleware stack and the route's ability), validate (the FormRequest,
 * whose rules come off the descriptor), call one service, render. The *shape* of each screen is
 * data, which is what spares the panel forty near-identical controllers.
 *
 * Two things it does that a tenant-facing controller would not:
 *
 * - **It audits reads.** An index or a record view leaves an `admin.resource.viewed` /
 *   `admin.record.viewed` entry, stamped with the tenant the rows belong to. An operator outside a
 *   business reading that business's customers is a privacy event, and the entry carries the
 *   resource, the filters and a count — never a row, a name or an address. A log of who looked at
 *   personal data must not itself be a copy of it.
 * - **It never writes what a module owns.** The generic writer only ever touches fields the
 *   descriptor declared, and `delete` is refused unless the descriptor opted in. Plan changes,
 *   member removals and erasures have their own routes that call the owning module's Action.
 */
final class ResourceController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ResourceQuery $query,
        private readonly ResourceWriter $writer,
        private readonly AdminAudit $audit,
    ) {}

    public function index(ResourceIndexRequest $request, string $resource): Response
    {
        $descriptor = $this->registry->find($resource);

        Gate::authorize($descriptor->ability()->gate());
        $options = $request->options();

        $rows = $this->query->paginate($descriptor, $options);

        $this->audit->record(
            AdminAudit::RESOURCE_VIEWED,
            null,
            [
                'resource' => $descriptor->key(),
                'results' => $rows->total(),
                ...$options->toAuditProperties(),
            ],
            $options->teamId,
        );

        return Inertia::render('admin/resources/Index', [
            'navigation' => $this->registry->navigation(),
            'resource' => $descriptor->toArray(),
            'filters' => $options->toFormState(),
            'teams' => $this->teamOptions($descriptor),
            'rows' => $this->rows($descriptor, $rows),
            'links' => $rows->linkCollection()->toArray(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(string $resource, string $id): Response
    {
        $descriptor = $this->registry->find($resource);

        Gate::authorize($descriptor->ability()->gate());
        $record = $this->findRecord($descriptor, $id);

        $this->audit->record(
            AdminAudit::RECORD_VIEWED,
            $record,
            ['resource' => $descriptor->key()],
            $descriptor->teamIdOf($record),
        );

        return Inertia::render('admin/resources/Show', [
            'navigation' => $this->registry->navigation(),
            'resource' => $descriptor->toArray(),
            'title' => $descriptor->title($record),
            'record' => $this->record($descriptor, $record, detail: true),
            'record_id' => $record->getKey(),
        ]);
    }

    public function create(string $resource): Response
    {
        $descriptor = $this->registry->find($resource);

        Gate::authorize($descriptor->ability()->gate());

        abort_unless($descriptor->allowsCreate(), 403);

        return Inertia::render('admin/resources/Form', [
            'navigation' => $this->registry->navigation(),
            'resource' => $descriptor->toArray(),
            'teams' => $this->teamOptions($descriptor),
            'record' => null,
            'record_id' => null,
            'values' => [],
        ]);
    }

    public function store(ResourceWriteRequest $request, string $resource): RedirectResponse
    {
        $descriptor = $this->registry->find($resource);

        Gate::authorize($descriptor->ability()->gate());

        // From `validated()`, not the raw input: an unvalidated id would reach the database as a
        // foreign-key violation rather than a form error.
        $record = $this->writer->create($descriptor, $request->payload(), $request->teamId());

        Inertia::flash('toast', ['type' => 'success', 'message' => $descriptor->singular().' created.']);

        return to_route('admin.resources.show', [$descriptor->key(), $record->getKey()]);
    }

    public function edit(string $resource, string $id): Response
    {
        $descriptor = $this->registry->find($resource);

        Gate::authorize($descriptor->ability()->gate());

        abort_unless($descriptor->allowsUpdate(), 403);

        $record = $this->findRecord($descriptor, $id);

        // Editing a record is reading it first, so it is audited as a read like any other.
        $this->audit->record(
            AdminAudit::RECORD_VIEWED,
            $record,
            ['resource' => $descriptor->key(), 'intent' => 'edit'],
            $descriptor->teamIdOf($record),
        );

        return Inertia::render('admin/resources/Form', [
            'navigation' => $this->registry->navigation(),
            'resource' => $descriptor->toArray(),
            'teams' => [],
            'record' => $this->record($descriptor, $record, detail: true),
            'record_id' => $record->getKey(),
            'values' => $this->values($descriptor, $record),
        ]);
    }

    public function update(ResourceWriteRequest $request, string $resource, string $id): RedirectResponse
    {
        $descriptor = $this->registry->find($resource);

        Gate::authorize($descriptor->ability()->gate());

        $record = $this->findRecord($descriptor, $id);

        $this->writer->update($descriptor, $record, $request->payload());

        Inertia::flash('toast', ['type' => 'success', 'message' => $descriptor->singular().' updated.']);

        return to_route('admin.resources.show', [$descriptor->key(), $record->getKey()]);
    }

    public function destroy(string $resource, string $id): RedirectResponse
    {
        $descriptor = $this->registry->find($resource);

        Gate::authorize($descriptor->ability()->gate());

        $record = $this->findRecord($descriptor, $id);

        // The writer refuses when the descriptor has not opted in — which is the default — so the
        // refusal is one rule in one place rather than a check repeated here.
        $this->writer->delete($descriptor, $record);

        Inertia::flash('toast', ['type' => 'success', 'message' => $descriptor->singular().' deleted.']);

        return to_route('admin.resources.index', $descriptor->key());
    }

    /**
     * No implicit route binding, because the model is not known until the registry has resolved the
     * descriptor. `findOrFail` reads across tenants, which is the whole point of the panel — and the
     * reason every path out of here is audited.
     */
    private function findRecord(AdminResource $descriptor, string $id): Model
    {
        $model = $descriptor->model();

        /** @var Model $record */
        $record = $model::query()
            ->with($descriptor->with())
            ->findOrFail($id);

        return $record;
    }

    /**
     * @param  LengthAwarePaginator<int, Model>  $rows
     * @return list<array<string, mixed>>
     */
    private function rows(AdminResource $descriptor, LengthAwarePaginator $rows): array
    {
        $out = [];

        foreach ($rows->items() as $record) {
            $out[] = [
                'id' => $record->getKey(),
                'title' => $descriptor->title($record),
                'cells' => $this->record($descriptor, $record),
            ];
        }

        return $out;
    }

    /**
     * Read only the declared columns, through `data_get` so a dotted relation column works.
     *
     * Enums and dates are reduced to scalars here rather than left to `json_encode`: a backed enum
     * would serialise as its value anyway, but a cast that is *not* backed, or an accessor returning
     * an object, would arrive as a shape the table cannot render.
     *
     * @return array<string, mixed>
     */
    private function record(AdminResource $descriptor, Model $record, bool $detail = false): array
    {
        $columns = $detail
            ? [...$descriptor->columns(), ...$descriptor->detailColumns()]
            : $descriptor->columns();

        $out = [];

        foreach ($columns as $column) {
            $out[$column->name] = $this->cell($record, $column);

            if ($column->currencyField !== null) {
                $out[$column->currencyField] = $this->scalar(data_get($record, $column->currencyField));
            }
        }

        return $out;
    }

    private function cell(Model $record, ResourceColumn $column): mixed
    {
        return $this->scalar(data_get($record, $column->name));
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_object($value)) {
            return (string) (method_exists($value, '__toString') ? $value : $value::class);
        }

        return $value;
    }

    /**
     * The form's current values — declared fields only, so a form never carries a column it cannot
     * submit back.
     *
     * @return array<string, mixed>
     */
    private function values(AdminResource $descriptor, Model $record): array
    {
        $values = [];

        foreach ($descriptor->fields() as $field) {
            $values[$field->name] = $this->scalar($record->getAttribute($field->name));
        }

        return $values;
    }

    /**
     * The team picker for a tenant-owned index. Capped, because the picker is a convenience and a
     * select with ten thousand options is neither usable nor cheap — past the cap an operator
     * filters by typing a team id, which the query string accepts directly.
     *
     * @return list<array{id: int, name: string}>
     */
    private function teamOptions(AdminResource $descriptor): array
    {
        if (! $descriptor->isTenantOwned()) {
            return [];
        }

        return array_values(Team::query()
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(static fn (Team $team): array => ['id' => (int) $team->id, 'name' => (string) $team->name])
            ->values()
            ->all());
    }
}
