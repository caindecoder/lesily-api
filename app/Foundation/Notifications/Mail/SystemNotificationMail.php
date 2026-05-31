<?php

namespace App\Foundation\Notifications\Mail;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\Notifications\Enums\NotificationType;

class SystemNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Email is retried 3 times with exponential backoff (1s, 5s, 15s) per
     * UC-1-087 Error Flow 5a / OQ-1-063 before the failure is logged.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15];

    public function __construct(
        public NotificationType $type,
        public string $companyName,
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
            subject: __('notifications.'.$this->type->value.'.subject', ['company' => $this->companyName]),
            replyTo: [config('mail.from.address')],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.system-notification');
    }
}
