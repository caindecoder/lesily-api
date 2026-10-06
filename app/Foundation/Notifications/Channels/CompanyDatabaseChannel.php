<?php

namespace App\Foundation\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * Laravel's database channel, plus the company the in-app record belongs to.
 */
class CompanyDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        /** @var SystemNotification $notification */
        return [
            ...parent::buildPayload($notifiable, $notification),
            'company_id' => $notification->companyId,
        ];
    }
}
