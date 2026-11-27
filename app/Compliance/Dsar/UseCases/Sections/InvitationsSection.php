<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use App\Companies\Memberships\Events\UserInvited;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\Events\UserJoinedCompany;
use App\Companies\Memberships\Events\UserInvitationResent;
use App\Companies\Memberships\Events\UserInvitationExpired;
use App\Companies\Memberships\Events\UserInvitationRevoked;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class InvitationsSection implements DsarSectionProvider
{
    /** The timeline name of each invitation event exported. */
    private const array EVENTS = [
        UserInvited::class => 'invited',
        UserInvitationResent::class => 'resent',
        UserInvitationRevoked::class => 'revoked',
        UserInvitationExpired::class => 'expired',
        UserJoinedCompany::class => 'joined',
    ];

    public function key(): string
    {
        return 'invitations';
    }

    /**
     * Each invitation of the subject's email, in any letter case, oldest
     * first, with its full history from the event store — never a token hash
     * or another person's id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        $invitationEloquentStoredEvents = LesilyStoredEvent::query()
            ->whereForCompany($companyId)
            ->where('event_class', UserInvited::class)
            ->whereRaw(
                "lower(json_unquote(json_extract(event_properties, '$.email'))) = lower(?)",
                [User::query()->findOrFail($subjectUserId)->email],
            )
            ->select('aggregate_uuid');

        $eloquentStoredEvents = LesilyStoredEvent::query()
            ->whereForCompany($companyId)
            ->whereIn('aggregate_uuid', $invitationEloquentStoredEvents)
            ->whereIn('event_class', array_keys(self::EVENTS))
            ->orderBy('id')
            ->get();

        $roleCodeCollection = Role::query()
            ->whereIn('id', $eloquentStoredEvents->pluck('event_properties.roleId')->filter()->unique())
            ->pluck('code', 'id');

        return $eloquentStoredEvents
            ->groupBy('aggregate_uuid')
            ->map(fn (Collection $streamCollection): array => [
                'timeline' => $streamCollection
                    ->map(fn (EloquentStoredEvent $eloquentStoredEvent): array => [
                        'event' => self::EVENTS[$eloquentStoredEvent->event_class],
                        'role' => $roleCodeCollection[$eloquentStoredEvent->event_properties['roleId'] ?? ''] ?? null,
                        'at' => CarbonImmutable::parse($eloquentStoredEvent->getAttribute('created_at'))->toISOString(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
