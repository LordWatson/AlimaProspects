<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Integrations\Enums\DeliveryState;
use App\Modules\Integrations\Enums\WebhookEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One attempt to post one event to one endpoint (PL7).
 *
 * The row is written **before** the request is made, which is what makes the log honest: a worker
 * killed mid-POST leaves a `pending` row rather than no evidence that anything was tried. It is
 * also what makes "redeliver" a job dispatch rather than a rebuild — the body it sends is the one
 * that was composed when the event happened, not what the lead looks like today, which is the whole
 * point of replaying a webhook.
 *
 * `payload` holds the envelope's `data` only. The rest of the envelope is built from this row at
 * send time ({@see self::envelope()}), so the stored json carries no second copy of what the row
 * already says and the two can never disagree.
 *
 * @property int $id
 * @property int $team_id
 * @property int $webhook_endpoint_id
 * @property WebhookEvent $event
 * @property array<string, mixed> $payload
 * @property DeliveryState $state
 * @property int $attempt
 * @property int|null $status_code
 * @property string|null $response_snippet
 * @property Carbon|null $delivered_at
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookDelivery extends Model
{
    use BelongsToTeam;

    /** A response body is somebody else's output and this is a column a screen renders. */
    public const int SNIPPET_BYTES = 1024;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'webhook_endpoint_id',
        'event',
        'payload',
        'state',
    ];

    /**
     * The whole signed body, as the receiver sees it.
     *
     * **No `team_id`.** The plan asked for an opaque public one, to avoid handing out the internal
     * key — but an endpoint belongs to exactly one team, so the field tells the receiver nothing
     * they do not already know, and the choice was between a column on `teams` with a backfill and
     * a value derived from `APP_KEY` that changes when the key is rotated. A field that carries no
     * information is not worth either (ADR 0034).
     *
     * `created_at` rather than the moment of sending, so a retry twelve hours later still says when
     * the thing it describes actually happened.
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'event' => $this->event->value,
            'created_at' => ($this->created_at ?? now())->toIso8601String(),
            'data' => $this->payload,
        ];
    }

    /**
     * The exact bytes to sign and send.
     *
     * One method, so the signature and the request body can never be built from two different
     * encodings of the same array. `JSON_UNESCAPED_SLASHES` only for legibility of the URLs inside;
     * nothing about verification depends on the flags, because the customer signs the bytes they
     * received rather than re-encoding them.
     */
    public function body(): string
    {
        return (string) json_encode($this->envelope(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Truncate a response body to something safe to store and show.
     */
    public static function snippet(string $body): ?string
    {
        $clean = Str::squish($body);

        return $clean === '' ? null : Str::limit($clean, self::SNIPPET_BYTES, '');
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => WebhookEvent::class,
            'payload' => 'array',
            'state' => DeliveryState::class,
            'attempt' => 'integer',
            'status_code' => 'integer',
            'delivered_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }
}
