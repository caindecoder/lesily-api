<?php

namespace App\Foundation\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Emails a guest the link to reset a forgotten password (UC-1-008). Queued so a slow mail send
 * never blocks the always-204 forgot-password response. The link lands on the web app's
 * reset-password page carrying the broker's single-use token AND the email the token is keyed to
 * (both are needed by the reset submit); the base URL is config('client.password_reset_url').
 */
class PasswordResetLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var CanResetPassword $notifiable */
        $url = config('client.password_reset_url')
            .'?token='.$this->token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset your password')
            ->line('You are receiving this email because a password reset was requested for your account.')
            ->action('Reset password', $url)
            ->line('This link expires in 60 minutes. If you did not request a reset, no action is needed.');
    }
}
