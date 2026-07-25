<?php

namespace App\Companies\OwnershipTransfers\IO\Mails;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The email leg of every ownership-transfer lifecycle notification (s151 ruling
 * H5). Takes the rendered subject and body from the notification subclass rather
 * than resolving them from lang keys.
 *
 * That is deliberate and is why this class exists at all instead of reusing
 * SystemNotificationMail: that Mailable builds its body from
 * `notifications.{type}.body`, and `lang/en/notifications.php` seeds SUBJECTS
 * ONLY — bodies are an explicit OQ-1-104 deferral to the notification-template
 * UC. So the existing path renders the literal lang key as the body for every
 * type, and could not carry a verbatim rejection reason for any of them. Reusing
 * it would have meant doing the template UC's work here, and widening a
 * `:company`-only placeholder contract for every notification in the system.
 *
 * Retries 3 times with exponential backoff before the failure is logged, matching
 * SystemNotificationMail (UC-1-087 error flow 5a / OQ-1-063).
 */
class OwnershipTransferNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15];

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
    ) {}

    /**
     * After the retries are exhausted the failure is logged for ops — the user is
     * never alerted, because the in-app notification already informed them.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: [config('mail.from.address')],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.ownership-transfer-notification');
    }
}
