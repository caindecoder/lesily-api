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
        $invitationIds = LesilyStoredEvent::query()
            ->whereForCompany($companyId)
            ->where('event_class', UserInvited::class)
            ->whereRaw(
                "lower(json_unquote(json_extract(event_properties, '$.email'))) = lower(?)",
                [User::query()->findOrFail($subjectUserId)->email],
            )
            ->select('aggregate_uuid');

        $events = LesilyStoredEvent::query()
            ->whereForCompany($companyId)
            ->whereIn('aggregate_uuid', $invitationIds)
            ->whereIn('event_class', array_keys(self::EVENTS))
            ->orderBy('id')
            ->get();

        $roleCodes = Role::query()
            ->whereIn('id', $events->pluck('event_properties.roleId')->filter()->unique())
            ->pluck('code', 'id');

        return $events
            ->groupBy('aggregate_uuid')
            ->map(fn (Collection $stream): array => [
                'timeline' => $stream
                    ->map(fn (EloquentStoredEvent $event): array => [
                        'event' => self::EVENTS[$event->event_class],
                        'role' => $roleCodes[$event->event_properties['roleId'] ?? ''] ?? null,
                        'at' => CarbonImmutable::parse($event->getAttribute('created_at'))->toISOString(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
