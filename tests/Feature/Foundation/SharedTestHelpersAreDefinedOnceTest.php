<?php

namespace Tests\Feature\Foundation;

use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

/**
 * A helper the test suite shares — in Tests\TestCase, a module base or a test
 * trait — is declared in one of those files only.
 */
class SharedTestHelpersAreDefinedOnceTest extends FoundationTestCase
{
    protected const int SHARED_FILES_AT_LEAST = 26;

    protected const array LIFECYCLE_METHODS = ['setup', 'teardown'];

    #[Test]
    public function when_the_shared_test_files_are_walked_then_no_helper_is_defined_in_two_of_them(): void
    {
        // Given the root test case, every module base and every test trait
        $files = $this->sharedTestFiles();

        // When the helper names each declares are collected
        $declaredIn = $this->helpersDeclaredIn($files);

        // Then the walk read the files and their helpers, and no name sits in two of them
        $this->assertWalkedAndNoneDefinedTwice($files, $declaredIn);
    }

    /**
     * @param  array<string, class-string>  $files
     * @param  array<string, list<string>>  $declaredIn
     */
    protected function assertWalkedAndNoneDefinedTwice(array $files, array $declaredIn): void
    {
        $this->assertGreaterThanOrEqual(self::SHARED_FILES_AT_LEAST, count($files));
        $this->assertArrayHasKey('tests/TestCase.php', $files);
        $this->assertSame(['tests/TestCase.php'], $declaredIn['seededrole'] ?? []);
        $this->assertSame(['tests/Feature/Companies/Memberships/CreatesMemberships.php'], $declaredIn['attachmember'] ?? []);
        $this->assertSame([], array_filter($declaredIn, fn (array $paths): bool => count($paths) > 1));
    }

    /**
     * The root test case, every *TestCase.php and every trait under tests/, keyed by path.
     *
     * @return array<string, class-string>
     */
    protected function sharedTestFiles(): array
    {
        $files = [];

        foreach (File::allFiles(base_path('tests')) as $file) {
            $path = 'tests/'.str_replace('\\', '/', $file->getRelativePathname());

            if ($path === 'tests/TestCase.php' || $this->isModuleBase($path) || $this->declaresATrait($file->getContents())) {
                /** @var class-string $class */
                $class = 'Tests\\'.str_replace(['/', '.php'], ['\\', ''], substr($path, strlen('tests/')));
                $files[$path] = $class;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Every method name (case-insensitive, as PHP reads it) with the files that declare it.
     *
     * @param  array<string, class-string>  $files
     * @return array<string, list<string>>
     */
    protected function helpersDeclaredIn(array $files): array
    {
        $declaredIn = [];

        foreach ($files as $path => $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($this->isOwnHelper($method, $reflection)) {
                    $declaredIn[strtolower($method->getName())][] = $path;
                }
            }
        }

        ksort($declaredIn);

        return $declaredIn;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    protected function isOwnHelper(ReflectionMethod $method, ReflectionClass $reflection): bool
    {
        return $method->getFileName() === $reflection->getFileName()
            && ! in_array(strtolower($method->getName()), self::LIFECYCLE_METHODS, true);
    }

    protected function isModuleBase(string $path): bool
    {
        return $path !== 'tests/TestCase.php' && str_ends_with($path, 'TestCase.php');
    }

    protected function declaresATrait(string $contents): bool
    {
        return preg_match('/^trait\s+\w+/m', $contents) === 1;
    }
}
