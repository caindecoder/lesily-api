<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
     * them, oldest first — never the verification notes, the prose
     * rejection reason, event ids or another person's id.
     *
     * @return array{transfers: array<int, array<string, mixed>>, cases: array<int, array<string, string|null>>}
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return [
            'transfers' => $this->transfers($subjectUserId, $companyId),
            'cases' => $this->cases($subjectUserId, $companyId),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transfers(string $subjectUserId, string $companyId): array
    {
        return LesilyStoredEvent::query()
            ->whereForCompany($companyId)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->where(fn (Builder $query) => $query
                ->where('event_properties->previousOwnerUserId', $subjectUserId)
                ->orWhere('event_properties->newOwnerUserId', $subjectUserId))
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
    private function cases(string $subjectUserId, string $companyId): array
    {
        return OwnershipTransferCase::query()
            ->forCompanyId($companyId)
            ->where(fn (Builder $query) => $query
                ->where('expected_owner_user_id', $subjectUserId)
                ->orWhere('new_owner_user_id', $subjectUserId))
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
