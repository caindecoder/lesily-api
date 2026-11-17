<?php

namespace Tests\Feature\Foundation;

use Spatie\LaravelData\Data;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

/**
 * A commands folder holds request objects only: each file declares a Data
 * class, directly or through a parent, named …Command — no trait, no value.
 */
class CommandFoldersHoldOnlyCommandsTest extends FoundationTestCase
{
    protected const int COMMAND_FILES_AT_LEAST = 73;

    #[Test]
    public function when_the_command_folders_are_walked_then_every_file_declares_a_data_class(): void
    {
        // Given every PHP file under app/**/UseCases/Commands
        $files = $this->commandFolderFiles();

        // When the files whose class is not a Data class are listed
        $notData = array_keys(array_filter($files, fn (string $class): bool => ! is_subclass_of($class, Data::class)));

        // Then the walk read the folders and listed none
        $this->assertWalkedAndNoneListed($files, $notData);
    }

    #[Test]
    public function when_the_command_folders_are_walked_then_every_class_is_named_command(): void
    {
        // Given every PHP file under app/**/UseCases/Commands
        $files = $this->commandFolderFiles();

        // When the files whose class name does not end in Command are listed
        $misnamed = array_keys(array_filter($files, fn (string $class): bool => ! str_ends_with(class_basename($class), 'Command')));

        // Then the walk read the folders and listed none
        $this->assertWalkedAndNoneListed($files, $misnamed);
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $listed
     */
    protected function assertWalkedAndNoneListed(array $files, array $listed): void
    {
        $this->assertGreaterThanOrEqual(self::COMMAND_FILES_AT_LEAST, count($files));
        $this->assertSame([], $listed);
    }

    /**
     * Every file under a UseCases/Commands folder, keyed by its path below app/.
     *
     * @return array<string, string>
     */
    protected function commandFolderFiles(): array
    {
        $files = [];

        foreach (File::allFiles(app_path()) as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());

            if (str_contains($path, 'UseCases/Commands/')) {
                $files[$path] = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $path);
            }
        }

        ksort($files);

        return $files;
    }
}
