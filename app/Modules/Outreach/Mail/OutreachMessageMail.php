<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Mail;

use App\Modules\Outreach\Data\SenderIdentity;
use App\Modules\Outreach\Models\OutreachMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * One outreach email, sent on a team's behalf (Phase 11).
 *
 * **Deliberately not an Alima-branded email, and deliberately not queued.**
 *
 * - *Not branded.* Every other mail the platform sends is Alima talking, and obeys the brand
 *   (CLAUDE.md §2a). This one is the **team** talking to their own prospect. Wrapping it in Alima's
 *   chrome would brand a tenant's cold email as Alima marketing — misattributing the sender, which
 *   is the one thing sender identification exists to prevent — and a decorated HTML cold email is
 *   also markedly worse at reaching an inbox. So the body is the member's text and nothing else,
 *   and Alima appears only in the footer's one identifying line. Recorded in
 *   docs/modules/outreach.md.
 * - *Not queued.* The member is watching, and "sent" has to mean sent: the status, the provider id
 *   and the timeline entry are written from the provider's answer. A failed send they can see and
 *   retry beats a queued one that fails quietly twenty minutes later.
 *
 * The footer is not optional and is not the member's to edit — sender identity, an unambiguous
 * opt-out and the privacy notice are the conditions under which the message may be sent at all
 * (docs/compliance.md §3.3, §3.7).
 */
final class OutreachMessageMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        private readonly OutreachMessage $message,
        private readonly SenderIdentity $sender,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The platform's verified address, named for the team: "Acme Ltd via Alima". Sending
            // from the team's own domain without its SPF/DKIM is how a tenant's mail lands in spam
            // (see config/outreach.php).
            from: new Address($this->sender->fromAddress, $this->sender->fromName),
            // A reply reaches the member, not a no-reply box — which is also the opt-out route the
            // footer offers, so it has to work.
            replyTo: [new Address($this->sender->replyTo, $this->sender->replyToName)],
            subject: (string) ($this->message->subject ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.outreach.message',
            text: 'emails.outreach.message-text',
            with: [
                'body' => $this->message->body,
                'senderName' => $this->sender->senderName,
                'senderWebsite' => $this->sender->senderWebsite,
                'optOutAddress' => $this->sender->optOutAddress,
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'privacyNoticeUrl' => $this->sender->privacyNoticeUrl,
                'isRtl' => $this->message->locale->direction()->value === 'rtl',
                'lang' => $this->message->locale->value,
            ],
        );
    }

    /**
     * `List-Unsubscribe` as well as the footer. The header is what a mail client turns into its own
     * unsubscribe button, and a recipient who uses it instead of replying is far less likely to
     * reach for "report spam" — which protects the deliverability of every other team on the
     * platform, not just this one.
     *
     * **`List-Unsubscribe-Post` goes in only when there is an HTTPS target** (Compliance, Phase 15).
     * RFC 8058 one-click requires one, and advertising it alongside a `mailto:`-only
     * `List-Unsubscribe` is worse than not advertising it at all: Gmail and Yahoo either ignore the
     * mismatch or treat it as a bad signal. Both routes are offered when the endpoint is on — the
     * HTTPS one first, because that is the one a client acts on.
     */
    public function headers(): Headers
    {
        $mailto = '<mailto:'.$this->sender->optOutAddress.'?subject=unsubscribe>';
        $url = $this->unsubscribeUrl();

        if ($url === null) {
            return new Headers(text: ['List-Unsubscribe' => $mailto]);
        }

        return new Headers(
            text: [
                'List-Unsubscribe' => '<'.$url.'>, '.$mailto,
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    /**
     * The one-click unsubscribe URL for *this* message, or null when the endpoint is switched off
     * or not registered.
     *
     * A **signed, expiring URL carrying the message id and nothing else**: it cannot be guessed,
     * reversed into an address, or walked to find other recipients. The lifetime is deliberately
     * long — somebody may unsubscribe from a year-old email in their archive, and that is the worst
     * possible moment to tell them the link has expired.
     *
     * `Route::has()` rather than a dependency on the Compliance module: Outreach stands up on its
     * own, and a missing route means the Phase 11 `mailto:` footer, which is what PECR accepts.
     */
    private function unsubscribeUrl(): ?string
    {
        if (! (bool) config('outreach.unsubscribe.enabled', true) || ! Route::has('unsubscribe.show')) {
            return null;
        }

        $days = max(1, (int) config('outreach.unsubscribe.link_days', 400));

        return URL::temporarySignedRoute('unsubscribe.show', now()->addDays($days), [
            'message' => $this->message->getKey(),
        ]);
    }
}
