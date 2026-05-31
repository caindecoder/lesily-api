<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Entities\NotificationPreference;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'notification_type' => NotificationType::GeneralAccountNotice->value,
            'channel' => SystemNotification::MAIL_CHANNEL,
            'enabled' => true,
        ];
    }

    public function mailDisabled(User $recipient, NotificationType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $recipient->getKey(),
            'notification_type' => $type->value,
            'channel' => SystemNotification::MAIL_CHANNEL,
            'enabled' => false,
        ]);
    }
}
