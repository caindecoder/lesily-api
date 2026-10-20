<?php

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Artisan;

class ListDevProcessesTest extends FoundationTestCase
{
    #[Test]
    public function when_the_dev_processes_are_listed_then_vite_is_not_among_them(): void
    {
        // Given the framework's default dev processes

        // When
        Artisan::call('dev:list', ['--json' => true]);
        $processes = json_decode(Artisan::output(), true);

        // Then
        $this->assertIsArray($processes);
        $this->assertDevProcessesRunWithoutVite(array_column($processes, 'name'));
    }

    protected function assertDevProcessesRunWithoutVite(array $names): void
    {
        $this->assertContains('server', $names);
        $this->assertNotContains('vite', $names);
    }
}
