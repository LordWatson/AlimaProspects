<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Services\AuditLogger;

/**
 * Ambient properties merged into every audit entry for the current request or job (Phase 19a).
 *
 * It exists for one problem that has no other clean answer: when an operator is **impersonating** a
 * customer, every write they make goes through the module's own Action, and that Action calls
 * {@see AuditLogger} with its own properties. There is no layer between the two to annotate.
 * Without this, the audit trail would record the customer doing it — which is the one fact about an
 * impersonated session that must not be lost.
 *
 * So the impersonation middleware pushes `impersonated_by` in here, and the logger picks it up
 * wherever it is called from. The alternative — teaching eighteen modules' Actions about
 * impersonation — is the coupling this codebase spends its seams avoiding.
 *
 * **Entry properties win.** A caller's own key is never overwritten by ambient context: the
 * Action knows what it is recording, and ambient data is annotation, not authority.
 *
 * Registered as **`scoped`**, not a singleton: Laravel flushes scoped instances between requests
 * and between queue jobs, so an annotation pushed in by one cannot stamp the next one's entries in
 * the same process. That matters more here than almost anywhere — a stale `impersonated_by` is not
 * a missing field, it is a *false attribution* in an audit trail. {@see self::forget()} exists for
 * a caller that wants to drop it mid-process anyway.
 */
final class AuditContext
{
    /**
     * @var array<string, mixed>
     */
    private array $properties = [];

    /**
     * @param  array<string, mixed>  $properties
     */
    public function add(array $properties): void
    {
        $this->properties = [...$this->properties, ...$properties];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->properties;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->properties);
    }

    public function forget(): void
    {
        $this->properties = [];
    }
}
