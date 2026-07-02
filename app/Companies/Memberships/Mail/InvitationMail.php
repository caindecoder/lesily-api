<?php

namespace App\Companies\Memberships\Mail;

use Throwable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The team-invite email (UC-1-018, R8): a raw queued Mailable to the invitee's
 * address — NOT a SystemNotification, because a brand-new invitee has no User
 * yet (D8). The accept-link carries the plaintext token; the invitations table
 * and the event stream hold only its sha256 (D3). Being queued, the plaintext
 * rides the serialized job payload until delivery — the accepted reset-link-
 * parity tradeoff.
 */
class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Retried 3 times with backoff before the failure is logged for ops — the
     * inviter can always resend (UC-1-021). SystemNotificationMail parity.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15];

    public function __construct(
        public string $companyName,
        public string $email,
        public string $plaintextToken,
        public CarbonInterface $expiresAt,
    ) {}

    public function failed(Throwable $exception): void
    {
        report($exception);
    }

    /**
     * The web app's accept-invitation page, carrying the single-use plaintext
     * token + the email it is keyed to (reset-link parity).
     */
    public function acceptUrl(): string
    {
        return config('client.invitation_url').'?token='.$this->plaintextToken.'&email='.urlencode($this->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to join :company on Lesily', ['company' => $this->companyName]),
            replyTo: [config('mail.from.address')],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invitation',
            with: ['acceptUrl' => $this->acceptUrl()],
        );
    }
}
