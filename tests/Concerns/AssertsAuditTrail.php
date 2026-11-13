<?php

namespace Tests\Concerns;

use App\Models\User;
use RuntimeException;
use Illuminate\Testing\TestResponse;
use App\Foundation\AuditTrail\Entities\AuditEntry;

trait AssertsAuditTrail
{
    protected const DEVICE = 'Lesily-Test/1.0 (Windows NT 10.0; Win64; x64)';

    protected const DEVICE_IP = '203.0.113.7';

    protected function failTheAuditTrail(): void
    {
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));
    }

    protected function failTheAuditTrailAfter(int $rows): void
    {
        $written = 0;
        AuditEntry::creating(function () use (&$written, $rows): void {
            if (++$written > $rows) {
                throw new RuntimeException('induced audit failure');
            }
        });
    }

    protected function assertNothingAudited(): void
    {
        $this->assertDatabaseCount('audit_trail', 0);
    }

    /** @return array<string, mixed> */
    protected function changesOf(AuditEntry $entry): array
    {
        $changes = $entry->metadata['changes'] ?? [];
        $this->assertIsArray($changes);
        array_walk($changes, fn (mixed &$change): bool => is_array($change) && ksort($change));
        ksort($changes);

        return $changes;
    }

    protected function assertEveryRowCameFrom(TestResponse $response, User $actor, string $roleCode): void
    {
        $requestId = $response->headers->get('Correlation-Id');
        $this->assertIsString($requestId);
        $entries = AuditEntry::query()->get();
        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertSame(
                [$actor->getKey(), $roleCode, $actor->name, self::DEVICE_IP, self::DEVICE, $requestId],
                [$entry->actor_user_id, $entry->actor_role_code, $entry->actor_label, $entry->ip_address, $entry->user_agent, $entry->request_id],
            );
        }
    }

    protected function fromADevice(): void
    {
        $this->withHeader('User-Agent', self::DEVICE)
            ->withServerVariables(['REMOTE_ADDR' => self::DEVICE_IP]);
    }
}
