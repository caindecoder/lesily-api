<?php

namespace App\Foundation\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Auth\Notifications\VerifyEmail;

class EmailVerificationLinkNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
