<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Listeners\RecordScheduledTaskRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pulse\Pulse;
use stdClass;
use Throwable;

/**
 * Failed jobs, queue depth and schedule health (Phase 19c) — what makes the platform runnable
 * without a shell.
 *
 * ## The thing Horizon cannot tell you
 *
 * A failed job's queue, class and exception are all Horizon shows, and the question an operator
 * actually has is **whose job was it**. Every queued job in this product belongs to a tenant, and
 * the answer is sitting in the serialised payload. {@see self::teamIdFromPayload()} digs it out —
 * **by pattern-matching the serialised string, never by unserialising it.** `unserialize()` on a
 * payload would instantiate whatever classes the string names, which is a remote-code-execution
 * primitive pointed at a table that exists precisely because something went wrong. A regex cannot
 * hurt anybody, and a missed id is an em dash rather than a breach.
 *
 * ## Queue depth is driver-dependent, and says so
 *
 * The `database` driver keeps pending work in a table this can read. Redis and SQS do not, and
 * this reports the driver's name and an honest "not readable here" rather than a zero — a queue
 * depth of zero is the single most reassuring number on an operations screen and must never be a
 * guess.
 *
 * ## Schedule health is *last seen*, not *last run*
 *
 * Laravel stores nothing about past scheduled runs, so {@see RecordScheduledTaskRun} writes each
 * one to the cache and this reads it back. A flushed cache therefore reports "never seen", which
 * is why every label here says *seen*.
 */
final class QueueHealth
{
    /** How the listener and this class agree on a cache key. One function, so they cannot disagree. */
    public static function scheduleCacheKey(string $command): string
    {
        return 'admin:schedule:last-seen:'.sha1($command);
    }

    /**
     * The stable identity of a scheduled task: its artisan command name.
     *
     * **Static, and shared with {@see RecordScheduledTaskRun}**, because the two sides have to
     * agree exactly or the screen reads "not seen" for a command that ran five minutes ago — a
     * silent failure in the one place that exists to surface silent failures.
     *
     * `Event::$command` carries the PHP binary and the artisan path, quoted by
     * `ProcessUtils::escapeArgument` — single quotes on Unix, **double** quotes on Windows. So the
     * name is taken from after the last `artisan` and whatever quoting surrounded it is stripped,
     * rather than matched against one platform's pattern.
     */
    public static function commandName(?string $command, ?string $description = null): string
    {
        if (! is_string($command) || $command === '') {
            return $description ?? 'closure';
        }

        $name = Str::of($command)->afterLast('artisan')->ltrim("'\" ")->trim()->value();

        return $name === '' ? $command : $name;
    }

    public function __construct(private readonly Cache $cache) {}

    /**
     * The failed-job table, newest first.
     *
     * Read directly rather than through a failer provider, because the `database` failer's
     * interface returns the decoded payload and nothing else — and the two columns this screen is
     * for are `queue` and the tenant hidden inside that payload.
     *
     * @return array<string, mixed>
     */
    public function failedJobs(int $limit = 50): array
    {
        if (! $this->hasTable('failed_jobs')) {
            return ['available' => false, 'driver' => null, 'total' => 0, 'jobs' => []];
        }

        $total = (int) DB::table('failed_jobs')->count();

        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn (stdClass $row): array => $this->presentFailedJob($row))
            ->values()
            ->all();

        return [
            'available' => true,
            'driver' => (string) config('queue.default'),
            'total' => $total,
            'jobs' => $jobs,
        ];
    }

    /**
     * One failed job by uuid, or null when it is no longer there.
     *
     * A single indexed lookup rather than a page of the newest N searched in PHP: the Actions need
     * the job class and the tenant id for their audit entries, and paging would quietly lose both
     * for anything older than the page — on the very screen whose reason to exist is a backlog.
     *
     * @return array<string, mixed>|null
     */
    public function describeFailedJob(string $uuid): ?array
    {
        if (! $this->hasTable('failed_jobs')) {
            return null;
        }

        $row = DB::table('failed_jobs')
            ->where('uuid', $uuid)
            ->first(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        return $row instanceof stdClass ? $this->presentFailedJob($row) : null;
    }

    /**
     * Pending work per queue, with the oldest job's age — which is the number that says whether a
     * worker is down, as opposed to busy.
     *
     * @return array<string, mixed>
     */
    public function queues(): array
    {
        $driver = (string) config('queue.default');
        $connection = (string) config('queue.connections.'.$driver.'.driver', $driver);

        if ($connection !== 'database' || ! $this->hasTable('jobs')) {
            return [
                'available' => false,
                'driver' => $connection,
                // Named rather than hidden: an operator on Redis needs to know *why* the figure is
                // missing, or they will read its absence as an empty queue.
                'note' => 'Queue depth is only readable on the database driver. This deployment uses '
                    .$connection.', so depth comes from Horizon or Pulse instead.',
                'queues' => [],
            ];
        }

        $now = CarbonImmutable::now('UTC');

        $queues = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as pending, MIN(available_at) as oldest, MAX(attempts) as attempts')
            ->groupBy('queue')
            ->orderByDesc('pending')
            ->get()
            ->map(static fn (object $row): array => [
                'queue' => (string) $row->queue,
                'pending' => (int) $row->pending,
                'oldest_at' => $row->oldest === null
                    ? null
                    : CarbonImmutable::createFromTimestampUTC((int) $row->oldest)->toIso8601String(),
                'oldest_age_seconds' => $row->oldest === null
                    ? null
                    : max(0, $now->getTimestamp() - (int) $row->oldest),
                'max_attempts_seen' => (int) $row->attempts,
            ])
            ->values()
            ->all();

        return [
            'available' => true,
            'driver' => $connection,
            'note' => null,
            'queues' => $queues,
        ];
    }

    /**
     * Every scheduled command, with when it was last seen running.
     *
     * The schedule is read from the live {@see Schedule} instance rather than from a list in this
     * class, so a command a module adds in its own provider appears here with no change — which is
     * the whole point, since the commands are declared across eight modules.
     *
     * @return list<array<string, mixed>>
     */
    public function schedule(Schedule $schedule): array
    {
        $rows = [];

        foreach ($schedule->events() as $event) {
            $command = self::commandName($event->command, $event->description);

            /** @var array<string, mixed>|null $seen */
            $seen = $this->cache->get(self::scheduleCacheKey($command));

            $rows[] = [
                'command' => $command,
                'expression' => $event->expression,
                'description' => $event->description,
                'next_due' => $this->nextDue($event),
                'last_seen_at' => $seen['at'] ?? null,
                'last_outcome' => $seen['outcome'] ?? null,
                'last_runtime_ms' => $seen['runtime_ms'] ?? null,
                'last_error' => $seen['error'] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['command'], (string) $b['command']));

        return $rows;
    }

    /**
     * Whether Laravel Pulse is installed and enabled here.
     *
     * A boolean rather than an embedded dashboard: Phase 20 wires Pulse properly, and this phase
     * does not wait for it. The screen links out where it is live and says where to look where it
     * is not.
     *
     * @return array<string, mixed>
     */
    public function pulse(): array
    {
        $installed = class_exists(Pulse::class);

        return [
            'installed' => $installed,
            'enabled' => $installed && (bool) config('pulse.enabled', false),
            'path' => $installed ? (string) config('pulse.path', 'pulse') : null,
        ];
    }

    /**
     * One `failed_jobs` row as the screen reads it.
     *
     * `stdClass`, because that is what the query builder returns — `failed_jobs` is a framework
     * table with no model, which is also why every value is cast explicitly here rather than
     * trusted.
     *
     * @return array<string, mixed>
     */
    private function presentFailedJob(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'connection' => (string) $row->connection,
            'queue' => (string) $row->queue,
            'job' => $this->jobNameFromPayload((string) $row->payload),
            'team_id' => $this->teamIdFromPayload((string) $row->payload),
            // The first line only. The whole trace is in the logs and in Sentry; a screenful of
            // frames per row makes the table unreadable and tells an operator nothing the first
            // line did not. It stays on this screen — behind `operations.manage` — and
            // deliberately never reaches an audit entry; see `DeleteFailedJobAction`.
            'exception' => Str::limit((string) Str::before((string) $row->exception, '
'), 240),
            'failed_at' => $this->iso((string) $row->failed_at),
        ];
    }

    /**
     * The job's class, from the payload's `displayName`.
     *
     * `displayName` rather than the `job` key, because for a queued listener or a mailable the
     * `job` key is the framework's generic `CallQueuedHandler@call` and the display name is the
     * thing an operator recognises.
     */
    private function jobNameFromPayload(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return 'Unknown';
        }

        $name = $decoded['displayName'] ?? $decoded['job'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'Unknown';
    }

    /**
     * The tenant a failed job belonged to, or null when the payload does not say.
     *
     * **Pattern-matched, never unserialised.** See the class note: `unserialize()` here would
     * instantiate classes named by a string in a table that exists because something already went
     * wrong, and no operations screen is worth that. Three forms are recognised, which between
     * them cover every job in this product:
     *
     *  - a `teamId` or `team_id` property on the job — including a private or protected one, whose
     *    serialised name is null-byte prefixed, which is why the pattern anchors on the closing
     *    quote rather than the opening one;
     *  - a serialised `Team` model, which Laravel replaces with a `ModelIdentifier` carrying the
     *    class and the key;
     *  - nothing, which renders as an em dash. A missed id is a gap on a screen; a wrong one would
     *    send an operator to the wrong customer.
     */
    private function teamIdFromPayload(string $payload): ?int
    {
        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;

        if (! is_string($command)) {
            return null;
        }

        if (preg_match('/team_?[Ii]d";i:(\d+);/', $command, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/Models\\\\Team";s:\d+:"id";i:(\d+);/', $command, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function nextDue(ScheduledEvent $event): ?string
    {
        try {
            return CarbonImmutable::instance($event->nextRunDate())->utc()->toIso8601String();
        } catch (Throwable) {
            // A `->when()` condition or an exotic expression can refuse to answer. An empty cell is
            // the honest result; a fabricated date would be read as a promise.
            return null;
        }
    }

    private function iso(string $value): ?string
    {
        try {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
