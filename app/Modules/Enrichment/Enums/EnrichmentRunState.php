<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

/**
 * Where one enrichment run got to (PL3).
 *
 * Its own enum rather than reusing `EnrichmentStatus`, which is the **lead's** free-tier pipeline
 * state (`pending`/`enriching`/`enriched`/`failed`) and means something else: a lead is "enriched"
 * as a standing fact, while a run is finished as an event. One enum for both would make
 * `enriched` ambiguous on every read.
 *
 * {@see self::Refused} is the case worth having and the one a first draft leaves out. A run stopped
 * before it spent anything — the feature is off, the plan does not include it, the lead was
 * enriched last week — is **not a failure**, and recording it as one would make every plan ceiling
 * look like an outage on the provider panel. It is the same distinction `signal_runs` draws with
 * its refusal codes.
 */
enum EnrichmentRunState: string
{
    /** Created, waiting for a worker. */
    case Queued = 'queued';

    /** A worker has it. */
    case Running = 'running';

    /** It finished. It does **not** mean anything was found. */
    case Completed = 'completed';

    /** It stopped before spending: a ceiling, a flag, or nothing to do. Not a failure. */
    case Refused = 'refused';

    /** Every provider errored, or the job died. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Looking',
            self::Completed => 'Finished',
            self::Refused => 'Not run',
            self::Failed => 'Failed',
        };
    }

    public function isInFlight(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    /**
     * Whether this state means the provider let us down — what the admin panel counts as a failure.
     *
     * A refusal is excluded on purpose: a team at its plan ceiling is not a vendor outage, and
     * counting it as one puts a red light on a provider that answered perfectly well.
     */
    public function isProviderFailure(): bool
    {
        return $this === self::Failed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
