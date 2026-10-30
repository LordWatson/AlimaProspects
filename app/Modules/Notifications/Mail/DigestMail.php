<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Data\DigestSummary;
use App\Modules\Notifications\Enums\DigestFrequency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

/**
 * The daily or weekly round-up (Phase 12).
 *
 * A Mailable rather than a notification channel, because a digest is **one email with sections** and
 * the notification pipeline is built around one notification with one line. It still goes out under
 * the team's locale, and the lines are interpolated in the view from the keys the
 * {@see DigestSummary} carries.
 *
 * Alima-branded, unlike an outreach message: this one *is* Alima talking (see the note in
 * `config/mail.php`).
 */
final class DigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Team $team,
        private readonly DigestSummary $summary,
        private readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->inTeamLocale(fn (): string => $this->summary->frequency === DigestFrequency::Weekly
                ? __('Your week on Alima')
                : __('Your day on Alima')),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notifications.digest',
            with: [
                'name' => $this->recipientName,
                'weekly' => $this->summary->frequency === DigestFrequency::Weekly,
                'lines' => $this->summary->lines,
                'total' => $this->summary->total,
                'settingsUrl' => url('/settings/notifications'),
                'dashboardUrl' => url('/dashboard'),
            ],
        );
    }

    /**
     * The queue worker's locale is whatever the last job left it as, so the subject cannot be left
     * to the ambient setting — the view is rendered under the same locale by the mailer's own
     * `locale()` handling, which the sender sets.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $render
     * @return TReturn
     */
    private function inTeamLocale(callable $render): mixed
    {
        $previous = App::getLocale();
        App::setLocale($this->team->locale);

        try {
            return $render();
        } finally {
            App::setLocale($previous);
        }
    }
}
