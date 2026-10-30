<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationPresenter;
use Spatie\LaravelData\Data;

/**
 * What one notification says (Phase 12) — as **translation keys plus params**, never as finished
 * prose.
 *
 * That is the whole point of the type. The same notification is read in three places with three
 * different ideas of when the words are chosen:
 *
 * - **In-app**, where the row was written days ago and the member may since have switched the app
 *   to Arabic. The keys and params are stored and the sentence is composed by `t()` at render
 *   time — exactly the rule `lead_activities.properties` follows.
 * - **Email and push**, which are rendered once, at send time, and so are run through `__()`
 *   under the **team's** locale before they leave.
 *
 * Built by {@see NotificationPresenter} from a type and its payload.
 */
final class NotificationContent extends Data
{
    /**
     * @param  string  $title  Translation key — the headline, and the email's subject.
     * @param  string  $body  Translation key — one sentence. Never more: this is read on a phone's
     *                        lock screen and in a 40-character-wide bell.
     * @param  array<string, string|int>  $params  Interpolated into both. Values are data (a
     *                                             company name, a count), so they are **not**
     *                                             translated.
     * @param  string|null  $url  Where clicking it goes, relative to the app root.
     * @param  string|null  $actionLabel  Translation key for the email's button, when it has one.
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $body,
        public readonly array $params = [],
        public readonly ?string $url = null,
        public readonly ?string $actionLabel = null,
    ) {}

    /**
     * The shape stored in `notifications.data` and read back by the bell.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'body' => $this->body,
            'params' => $this->params,
            'url' => $this->url,
        ];
    }
}
