<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use Carbon\CarbonImmutable;
use App\Companies\Entities\OwnershipTransferCase;
use App\Companies\Events\CompanyOwnershipTransferred;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class OwnershipTransferCasesSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'ownership_transfers';
    }

    /**
     * The ownership transfers the subject took part in — self-service or
     * admin-assisted, from the event store — and the support cases that name
     * them, oldest first (ruling N32): never the verification notes, the prose
     * rejection reason, event ids or another person's id.
     *
     * @return array{transfers: array<int, array<string, string>>, cases: array<int, array<string, string|null>>}
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return [
            'transfers' => $this->transfers($subjectUserId),
            'cases' => $this->cases(),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function transfers(string $subjectUserId): array
    {
        return LesilyStoredEvent::query()
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->orderBy('id')
            ->get()
            ->map(fn (EloquentStoredEvent $transfer): array => [
                'transferred_at' => CarbonImmutable::parse($transfer->getAttribute('created_at'))->toISOString(),
                'role' => $transfer->event_properties['previousOwnerUserId'] === $subjectUserId ? 'previous_owner' : 'new_owner',
                'source' => $transfer->event_properties['source'],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function cases(): array
    {
        return OwnershipTransferCase::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (OwnershipTransferCase $case): array => [
                'status' => $case->status->value,
                'outcome' => $case->closed_outcome?->value,
                'rejection_reason_code' => $case->rejection_reason_code?->value,
                'requested_context' => $case->requested_context,
                'opened_at' => $case->created_at->toISOString(),
                'verified_at' => $case->verified_at?->toISOString(),
                'executed_at' => $case->executed_at?->toISOString(),
            ])
            ->all();
    }
}
