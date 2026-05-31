<?php

namespace App\Foundation\Notifications\Mail;

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

    public function __construct(
        public NotificationType $type,
        public string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('notifications.'.$this->type->value.'.subject', ['company' => $this->companyName]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.system-notification');
    }
}
