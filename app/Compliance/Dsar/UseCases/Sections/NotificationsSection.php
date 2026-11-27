<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use App\Foundation\Notifications\Entities\NotificationPreference;

class NotificationsSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'notifications';
    }

    /**
     * The notices the subject received about this company or their whole
     * account, oldest first, and the notification preferences they hold —
     * never the notice's class or anyone's id. An account-wide notice carries
     * no company name: its label may name another of their companies.
     *
     * @return array{notifications: array<int, array<string, mixed>>, preferences: array<int, array<string, mixed>>}
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return [
            'notifications' => $this->notificationsOf($subjectUserId, $companyId),
            'preferences' => $this->preferencesOf($subjectUserId),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function notificationsOf(string $subjectUserId, string $companyId): array
    {
        return User::query()->findOrFail($subjectUserId)
            ->notifications()
            ->where(fn (Builder $builder): Builder => $builder->where('company_id', $companyId)->orWhereNull('company_id'))
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (DatabaseNotification $databaseNotification): array => [
                'notification_type' => $databaseNotification->data['notification_type'],
                'company_name' => $databaseNotification->getAttribute('company_id') === null ? null : $databaseNotification->data['company_name'],
                'sent_at' => $databaseNotification->created_at?->toISOString(),
                'read_at' => $databaseNotification->read_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function preferencesOf(string $subjectUserId): array
    {
        return NotificationPreference::query()
            ->where('user_id', $subjectUserId)
            ->orderBy('notification_type')
            ->orderBy('channel')
            ->get()
            ->map(fn (NotificationPreference $notificationPreference): array => [
                'notification_type' => $notificationPreference->notification_type,
                'channel' => $notificationPreference->channel,
                'enabled' => $notificationPreference->enabled,
                'changed_at' => $notificationPreference->updated_at?->toISOString(),
            ])
            ->all();
    }
}
