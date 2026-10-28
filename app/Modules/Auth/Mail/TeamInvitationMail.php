<?php

declare(strict_types=1);

namespace App\Modules\Auth\Mail;

use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Auth\Support\InvitationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation email carrying the time-limited signed acceptance link. Voice: bright, warm,
 * together (brand §6).
 */
final class TeamInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly TeamInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to join {$this->teamName()} on Alima",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.team-invitation',
            with: [
                'teamName' => $this->teamName(),
                'inviterName' => $this->invitation->inviter?->name,
                'roleLabel' => $this->invitation->roleEnum()->label(),
                'acceptUrl' => InvitationUrl::for($this->invitation),
            ],
        );
    }

    private function teamName(): string
    {
        return $this->invitation->team()->first()->name;
    }
}
