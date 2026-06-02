<?php

namespace Tests\Feature\Billing;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Billing\Subscriptions\UseCases\Commands\ApplyTrialExpirationCommand;

class ApplyTrialExpirationCommandTest extends BillingTestCase
{
    // Sad — the command validates its identifiers at the use-case boundary.
    #[Test]
    #[DataProvider('provideNonUuidIdentifiers')]
    public function when_an_expiry_command_has_a_non_uuid_identifier_then_it_is_rejected(string $companyId, string $subscriptionId): void
    {
        $this->expectException(ValidationException::class);

        ApplyTrialExpirationCommand::validateAndCreate([
            'companyId' => $companyId,
            'subscriptionId' => $subscriptionId,
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideNonUuidIdentifiers(): array
    {
        return [
            'non-uuid company id' => ['not-a-uuid', '22222222-2222-4222-8222-222222222222'],
            'non-uuid subscription id' => ['11111111-1111-4111-8111-111111111111', 'not-a-uuid'],
        ];
    }
}
