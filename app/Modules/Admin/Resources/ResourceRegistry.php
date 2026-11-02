<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Enums\ResourceGroup;
use Illuminate\Contracts\Container\Container;

/**
 * Every resource the admin panel exposes, resolved from `config/admin.php` (Phase 19a).
 *
 * The config list is the answer to "what can an operator see and change?" — one file, readable in
 * a minute, diffed in review. A registry that discovered its descriptors by scanning the namespace
 * would answer the same question only by running the application, and would quietly pick up a
 * descriptor somebody left half-written.
 *
 * Resolved through the container so a descriptor may take dependencies if it ever needs to; none
 * does today, and the moment one needs *logic* it should be a bespoke screen instead (see
 * {@see AdminResource}).
 */
final class ResourceRegistry
{
    /**
     * @var array<string, AdminResource>|null
     */
    private ?array $resources = null;

    public function __construct(private readonly Container $app) {}

    /**
     * @return array<string, AdminResource>
     */
    public function all(): array
    {
        if ($this->resources !== null) {
            return $this->resources;
        }

        /** @var mixed $declared */
        $declared = config('admin.resources', []);
        $resources = [];

        foreach (is_array($declared) ? $declared : [] as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $resource = $this->app->make($class);

            if (! $resource instanceof AdminResource) {
                continue;
            }

            $resources[$resource->key()] = $resource;
        }

        return $this->resources = $resources;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * A **404**, not a domain exception.
     *
     * A URL naming no resource is a routing failure in the same way a missing record is: the
     * operator has followed a stale link or mistyped, and the honest answer is the page not being
     * there. A `DomainException` would be rendered by the central handler as a toast and a bounce
     * (see `bootstrap/app.php`), which is the right treatment for "you may not do that" and the
     * wrong one for "that does not exist" — and either way it beats a null descriptor failing three
     * layers down.
     */
    public function find(string $key): AdminResource
    {
        $resource = $this->all()[$key] ?? null;

        abort_if($resource === null, 404, 'There is no admin resource called "'.$key.'".');

        return $resource;
    }

    /**
     * Grouped and ordered for the nav: groups by {@see ResourceGroup::position()}, resources
     * alphabetically inside each.
     *
     * @return list<array{group: ResourceGroup, resources: list<AdminResource>}>
     */
    public function grouped(): array
    {
        $byGroup = [];

        foreach ($this->all() as $resource) {
            $byGroup[$resource->group()->value][] = $resource;
        }

        $groups = [];

        foreach (ResourceGroup::cases() as $group) {
            $resources = $byGroup[$group->value] ?? [];

            if ($resources === []) {
                continue;
            }

            usort($resources, static fn (AdminResource $a, AdminResource $b): int => strcmp($a->label(), $b->label()));

            $groups[] = ['group' => $group, 'resources' => $resources];
        }

        usort($groups, static fn (array $a, array $b): int => $a['group']->position() <=> $b['group']->position());

        return $groups;
    }

    /**
     * The nav payload: group label, then each resource's key and label.
     *
     * @return list<array{label: string, items: list<array{key: string, label: string}>}>
     */
    public function navigation(): array
    {
        return array_map(static fn (array $section): array => [
            'label' => $section['group']->label(),
            'items' => array_map(static fn (AdminResource $resource): array => [
                'key' => $resource->key(),
                'label' => $resource->label(),
            ], $section['resources']),
        ], $this->grouped());
    }
}
