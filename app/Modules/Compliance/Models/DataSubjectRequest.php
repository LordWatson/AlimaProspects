<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\User;
use App\Modules\Compliance\Enums\SubjectRequestStatus;
use App\Modules\Compliance\Enums\SubjectRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request from somebody who is not a user (PL5).
 *
 * **Platform-level, so no `BelongsToTeam` and no `team_id`** — deliberately twice over. The person
 * wrote to Alima, not to a tenant, and the request reaches every team holding them; and a tenant
 * must not be able to read it, because it names somebody another customer may also hold. Only the
 * admin panel reads this table.
 *
 * `email` is stored in the clear, which looks wrong on a privacy table and is not: we cannot write
 * back to a hash, and a request we cannot verify or answer is a request we have refused. It is the
 * one personal datum the person handed us on purpose, for this purpose, and the retention sweep
 * takes it with the rest.
 *
 * @property int $id
 * @property SubjectRequestType $type
 * @property SubjectRequestStatus $status
 * @property string $email
 * @property string|null $name
 * @property string|null $company
 * @property string|null $details
 * @property string|null $phone
 * @property Carbon|null $verified_at
 * @property Carbon|null $due_at
 * @property array<string, int>|null $purge_summary
 * @property Carbon|null $purged_at
 * @property int|null $handled_by
 * @property string|null $resolution_note
 * @property Carbon|null $completed_at
 * @property string|null $submitted_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DataSubjectRequest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'status',
        'email',
        'name',
        'company',
        'details',
        'phone',
        'submitted_ip',
    ];

    /**
     * The operator who closed it. `nullOnDelete`, because the request outlives the staff member who
     * handled it — which is the whole point of an accountability record.
     *
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Whether the statutory month has run out on an open request.
     *
     * False while it is unverified: the clock starts at verification, because the month runs from a
     * request we can actually act on.
     */
    public function isOverdue(): bool
    {
        return ! $this->status->isClosed()
            && $this->due_at instanceof Carbon
            && $this->due_at->isPast();
    }

    /**
     * Days left on the clock, negative once it has passed. Null while unverified.
     */
    public function daysRemaining(): ?int
    {
        return $this->due_at instanceof Carbon
            ? (int) Carbon::now()->startOfDay()->diffInDays($this->due_at->startOfDay(), absolute: false)
            : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SubjectRequestType::class,
            'status' => SubjectRequestStatus::class,
            'verified_at' => 'datetime',
            'due_at' => 'datetime',
            'purge_summary' => 'array',
            'purged_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
