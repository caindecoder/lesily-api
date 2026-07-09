<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * UC-1-139 Set Default Company (slice-companies Task 2) — the persisted
 * default-company preference behind PUT/DELETE /api/v1/me/default-company:
 * a plain users.default_company_id write (NOT event-sourced — B2), guarded
 * to live memberships only, collapsed to 422 on any non-live target (no
 * company-id-existence leak), idempotent on both verbs.
 */
class DefaultCompanyTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
    }

    #[Test]
    public function when_setting_default_without_token_then_unauthenticated(): void
    {
        // Given no authenticated session

        // When
        $response = $this->putJson('/api/v1/me/default-company', [
            'companyId' => Str::uuid7()->toString(),
        ]);

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_clearing_default_without_token_then_unauthenticated(): void
    {
        // Given no authenticated session

        // When
        $response = $this->deleteJson('/api/v1/me/default-company');

        // Then
        $this->assertUnauthenticated($response);
    }
}
