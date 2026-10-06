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
    private function notificationsOf(string $subjectUserId, string $companyId): array
    {
        return User::query()->findOrFail($subjectUserId)
            ->notifications()
            ->where(fn (Builder $query): Builder => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'notification_type' => $notification->data['notification_type'],
                'company_name' => $notification->getAttribute('company_id') === null ? null : $notification->data['company_name'],
                'sent_at' => $notification->created_at?->toISOString(),
                'read_at' => $notification->read_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function preferencesOf(string $subjectUserId): array
    {
        return NotificationPreference::query()
            ->where('user_id', $subjectUserId)
            ->orderBy('notification_type')
            ->orderBy('channel')
            ->get()
            ->map(fn (NotificationPreference $preference): array => [
                'notification_type' => $preference->notification_type,
                'channel' => $preference->channel,
                'enabled' => $preference->enabled,
                'changed_at' => $preference->updated_at?->toISOString(),
            ])
            ->all();
    }
}
