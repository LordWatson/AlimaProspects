<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Enums\FieldType;
use App\Modules\Admin\Resources\AdminResource;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\ResourceWriter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a registry write against the descriptor that declared the fields (Phase 19a).
 *
 * This is what makes the generic writer a **validated** write path rather than mass assignment
 * with a Vue component in front of it. The rules are not written here — they are read off the
 * resource's `fields()`, which is also what rendered the form. One declaration, two consumers, no
 * way for the two to disagree.
 *
 * It is a FormRequest and not a `Validator::make()` inside the service on purpose: CLAUDE.md §2
 * puts validation in FormRequests, and the fact that the rules are computed does not change where
 * they belong. The resource is resolved from the route rather than injected, because a FormRequest
 * is built before the controller is.
 *
 * `payload()` returns **only declared fields**, cast by their `FieldType`. A field the descriptor
 * does not declare cannot reach the model even if it is fillable on it and even if it was posted —
 * which is the property that lets {@see ResourceWriter} use
 * `forceFill` safely.
 */
final class ResourceWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->resource()->fields() as $field) {
            $rules[$field->name] = $field->rules;
        }

        // On a **create** of a tenant-owned resource the tenant is a required, existing team.
        //
        // Required because the panel runs outside every tenant, so `BelongsToTeam`'s auto-assign
        // has nothing to assign and a row created without one belongs to nobody. `exists` because
        // without it a mistyped id reaches the database as a foreign-key violation — a 500 for what
        // is an ordinary typo, and on a table without the constraint it would be worse than that:
        // a row quietly attached to a tenant that does not exist.
        if ($this->isCreate() && $this->resource()->isTenantOwned()) {
            $rules['team_id'] = ['required', 'integer', 'exists:teams,id'];
        }

        return $rules;
    }

    /**
     * Whether this is a create rather than an update, read from the route name rather than the
     * verb — `store` is the only route that reaches this request without a record in hand.
     */
    public function isCreate(): bool
    {
        return $this->route()?->getName() === 'admin.resources.store';
    }

    /**
     * The tenant a create was told to attach the row to, or null for a platform-wide resource.
     */
    public function teamId(): ?int
    {
        $teamId = $this->validated('team_id');

        return is_numeric($teamId) ? (int) $teamId : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = ['team_id' => 'tenant'];

        foreach ($this->resource()->fields() as $field) {
            $attributes[$field->name] = strtolower($field->label);
        }

        return $attributes;
    }

    /**
     * The attributes to write: declared fields only, each cast for its control.
     *
     * A boolean is read from the raw input rather than from `validated()`, because an unchecked
     * checkbox is **absent** from the request rather than present and false — and a nullable
     * boolean rule lets that absence through, so reading only what validated would silently leave
     * the old value in place and make the toggle appear not to work one way round.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $attributes = [];

        foreach ($this->resource()->fields() as $field) {
            if ($field->type === FieldType::Boolean) {
                $attributes[$field->name] = $this->boolean($field->name);

                continue;
            }

            if (! array_key_exists($field->name, $validated)) {
                continue;
            }

            $attributes[$field->name] = $field->type->cast($validated[$field->name]);
        }

        return $attributes;
    }

    public function resource(): AdminResource
    {
        /** @var string $key */
        $key = $this->route('resource');

        return app(ResourceRegistry::class)->find($key);
    }
}
