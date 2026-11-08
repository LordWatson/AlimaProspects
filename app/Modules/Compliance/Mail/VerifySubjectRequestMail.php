<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Mail;

use App\Modules\Compliance\Enums\SubjectRequestType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Confirm your privacy request" (PL5).
 *
 * A `Mailable` and **not** `NotificationDispatcher`, which is the exception CLAUDE.md's rule allows
 * for: the dispatcher resolves a member's per-team channel preferences and stamps a `team_id`, and
 * the recipient here has no account, no team and no preferences. They are a member of the public
 * who typed their address into a form.
 *
 * It takes the type and the URL rather than the model, so nothing about the request — not the
 * company they named, not the free text they wrote — is serialised onto the queue.
 */
final class VerifySubjectRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SubjectRequestType $type,
        public readonly string $verifyUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your privacy request');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verify-subject-request',
            with: [
                'requestLabel' => $this->type->label(),
                'verifyUrl' => $this->verifyUrl,
                'days' => max(1, (int) config('compliance.subject_requests.link_days', 14)),
            ],
        );
    }
}
