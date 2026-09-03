<?php

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * @testclass-carveout: infra-primitive — the schema-reuse contract of the base
 * test case (conv-plan §2.1, OQ-1-181) is provable only at the migration seam;
 * no HTTP walk can observe which migration command a worker ran.
 */
class InfectionWorkersReuseTheMigratedSchemaTest extends FoundationTestCase
{
    private const MARKER = 'zz_9999_99_99_000000_oq_1_181_marker';

    protected function tearDown(): void
    {
        putenv('INFECTION');

        parent::tearDown();
    }

    #[Test]
    public function when_the_process_is_not_an_infection_worker_then_the_schema_is_migrated_fresh(): void
    {
        // Given an ordinary test process (ParaTest, phpunit, artisan test) with a stale marker in the schema
        $this->markThisProcessAsAnOrdinaryRunner();
        $this->plantMigrationMarker();

        // When the base class migrates for the first test of the process
        $this->migrateDatabases();

        // Then the schema was rebuilt from scratch and the stale marker is gone
        $this->assertSchemaMigratedFresh();
        RefreshDatabaseState::$migrated = true;
    }

    private function markThisProcessAsAnOrdinaryRunner(): void
    {
        putenv('INFECTION');
        RefreshDatabaseState::$migrated = false;
        $this->withoutMockingConsoleOutput();
    }

    private function plantMigrationMarker(): void
    {
        $this->app['db']->table('migrations')->insert(['migration' => self::MARKER, 'batch' => 999]);
    }

    protected function assertSchemaMigratedFresh(): void
    {
        $this->assertStringContainsString('Dropping all tables', Artisan::output());
        $this->assertDatabaseMissing('migrations', ['migration' => self::MARKER]);
    }
}
