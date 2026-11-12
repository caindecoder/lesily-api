<?php

namespace Tests\Concerns;

use RuntimeException;
use App\Foundation\AuditTrail\Entities\AuditEntry;

trait AssertsAuditTrail
{
    protected const DEVICE = 'Lesily-Test/1.0 (Windows NT 10.0; Win64; x64)';

    protected const DEVICE_IP = '203.0.113.7';

    protected function failTheAuditTrail(): void
    {
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));
    }

    protected function assertNothingAudited(): void
    {
        $this->assertDatabaseCount('audit_trail', 0);
    }

    protected function fromADevice(): void
    {
        $this->withHeader('User-Agent', self::DEVICE)
            ->withServerVariables(['REMOTE_ADDR' => self::DEVICE_IP]);
    }
}
