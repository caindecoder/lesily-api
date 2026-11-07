<?php

namespace App\Compliance\Breach\Mail;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;

class BreachRegulatorNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15];

    /**
     * @param  array<string, string>  $part  the company's own part of the incident (BreachCompanyPartRenderer)
     */
    public function __construct(
        public string $companyName,
        public array $part,
    ) {}

    /**
     * The filing stays recorded after the retries; the failure is reported for an
     * admin to resend by hand (UC-1-131 5a).
     */
    public function failed(Throwable $exception): void
    {
        report($exception);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('notifications.breach_regulator_notice.subject', ['company' => $this->companyName]),
            replyTo: [config('mail.from.address')],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.breach-regulator-notice');
    }
}
