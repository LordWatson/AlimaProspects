<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Data;

use App\Modules\LeadsWorkspace\Http\Requests\SaveLeadTaskRequest;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for a lead task (from {@see SaveLeadTaskRequest}). Timestamps arrive as
 * ISO-8601 or `Y-m-d H:i` strings and are normalised to UTC here, because the column is UTC and the
 * team reads it in their own timezone (CLAUDE.md §2).
 */
final class LeadTaskData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly ?int $assignedTo = null,
        public readonly ?DateTimeImmutable $dueAt = null,
        public readonly ?DateTimeImmutable $remindAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            title: Str::limit(Str::squish((string) ($validated['title'] ?? '')), 160, ''),
            assignedTo: isset($validated['assigned_to']) ? (int) $validated['assigned_to'] : null,
            dueAt: self::timestamp($validated['due_at'] ?? null),
            // A reminder with no due date is still a reminder ("chase this on Friday"), so the two
            // are independent.
            remindAt: self::timestamp($validated['remind_at'] ?? null),
        );
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Date::parse($value)->utc()->toDateTimeImmutable();
    }
}
