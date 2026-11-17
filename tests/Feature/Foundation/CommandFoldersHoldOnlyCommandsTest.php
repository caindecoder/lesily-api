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
    /**
     * @var list<string>
     */
    protected const array KNOWN_NOT_COMMANDS = [
        'Companies/UseCases/Commands/VerificationArtefact.php',
        'Compliance/Breach/UseCases/Commands/AffectedCompanyData.php',
    ];

    #[Test]
    public function when_the_command_folders_are_walked_then_every_file_declares_a_data_class(): void
    {
        // Given every PHP file under app/**/UseCases/Commands
        $files = $this->commandFolderFiles();

        // When the files whose class is not a Data class are listed
        $notData = array_keys(array_filter($files, fn (string $class): bool => ! is_subclass_of($class, Data::class)));

        // Then the walk read the folders and listed none outside the known list
        $this->assertNotEmpty($files);
        $this->assertSame([], $this->offendersOutsideKnownList($notData));
    }

    #[Test]
    public function when_the_command_folders_are_walked_then_every_class_is_named_command(): void
    {
        // Given every PHP file under app/**/UseCases/Commands
        $files = $this->commandFolderFiles();

        // When the files whose class name does not end in Command are listed
        $misnamed = array_keys(array_filter($files, fn (string $class): bool => ! str_ends_with(class_basename($class), 'Command')));

        // Then the walk read the folders and listed none outside the known list
        $this->assertNotEmpty($files);
        $this->assertSame([], $this->offendersOutsideKnownList($misnamed));
    }

    #[Test]
    public function when_the_known_list_is_read_then_it_names_only_files_that_still_break_the_rule(): void
    {
        // Given every PHP file under app/**/UseCases/Commands
        $files = $this->commandFolderFiles();

        // When the known entries that are gone or now follow the rule are listed
        $stale = array_values(array_filter(
            self::KNOWN_NOT_COMMANDS,
            fn (string $path): bool => ! isset($files[$path]) || $this->isCommand($files[$path]),
        ));

        // Then the list names no such entry
        $this->assertSame([], $stale);
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

    protected function isCommand(string $class): bool
    {
        return is_subclass_of($class, Data::class) && str_ends_with(class_basename($class), 'Command');
    }

    /**
     * @param  list<string>  $offenders
     * @return list<string>
     */
    protected function offendersOutsideKnownList(array $offenders): array
    {
        return array_values(array_diff($offenders, self::KNOWN_NOT_COMMANDS));
    }
}
