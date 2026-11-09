<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

/**
 * Where one webhook delivery stands (PL7).
 *
 * A stored column rather than something derived from `delivered_at` and `next_retry_at`, because
 * the delivery log filters and counts on it and two nullable timestamps encoding three states is
 * the kind of query that gets one of them wrong. There is no `running`: a row is `Pending` from the
 * moment it is written until it is one of the other two, which means a worker killed mid-POST
 * leaves a row that reads honestly rather than one claiming to be in flight for ever.
 */
enum DeliveryState: string
{
    /** Queued, or waiting on a retry. */
    case Pending = 'pending';

    /** The endpoint answered 2xx. */
    case Delivered = 'delivered';

    /** Every attempt is spent. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
        };
    }
}
