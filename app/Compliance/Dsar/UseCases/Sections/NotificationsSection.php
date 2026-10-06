<?php

namespace App\Compliance\Dsar\UseCases\Sections;

class NotificationsSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'notifications';
    }

    /**
     * @return array{notifications: array<int, array<string, mixed>>, preferences: array<int, array<string, mixed>>}
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return [
            'notifications' => [],
            'preferences' => [],
        ];
    }
}
