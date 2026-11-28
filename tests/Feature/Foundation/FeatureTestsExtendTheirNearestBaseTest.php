<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\File;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Companies\Memberships\MembershipsTestCase;

/**
 * Every feature test extends the nearest module base up its path: its own
 * folder's *TestCase, or the closest folder above that has one.
 */
class FeatureTestsExtendTheirNearestBaseTest extends FoundationTestCase
{
    protected const int TEST_CLASSES_AT_LEAST = 150;

    protected const string FEATURE_ROOT = 'tests/Feature';

    #[Test]
    public function when_feature_tests_are_walked_then_each_extends_the_nearest_base_up_its_path(): void
    {
        // Given every test class under tests/Feature
        $tests = $this->featureTestClasses();

        // When each one's parent is compared with the nearest bases up its path
        $offenders = $this->classesOffTheirNearestBase($tests);

        // Then the walk read the tests and every one extends a nearest base
        $this->assertWalkedAndEveryTestOnItsBase($tests, $offenders);
    }

    /**
     * @param  array<string, class-string>  $tests
     * @param  list<string>  $offenders
     */
    protected function assertWalkedAndEveryTestOnItsBase(array $tests, array $offenders): void
    {
        $walked = count($tests);
        $this->assertGreaterThanOrEqual(self::TEST_CLASSES_AT_LEAST, $walked, "walked {$walked} feature test classes, expected at least ".self::TEST_CLASSES_AT_LEAST);
        $this->assertSame([], $offenders);
        $this->assertSame([AuthTestCase::class], $this->nearestBasesFor(self::FEATURE_ROOT.'/Auth/TwoFactor/DisableTwoFactorTest.php'));
        $this->assertSame([MembershipsTestCase::class], $this->nearestBasesFor(self::FEATURE_ROOT.'/Companies/Memberships/AcceptConsentTest.php'));
    }

    /**
     * Every *Test.php under tests/Feature, keyed by its path.
     *
     * @return array<string, class-string>
     */
    protected function featureTestClasses(): array
    {
        $tests = [];

        foreach (File::allFiles(base_path(self::FEATURE_ROOT)) as $splFileInfo) {
            $relative = str_replace('\\', '/', $splFileInfo->getRelativePathname());

            if (str_ends_with($relative, 'Test.php')) {
                /** @var class-string $class */
                $class = $this->classOf($relative);
                $tests[self::FEATURE_ROOT.'/'.$relative] = $class;
            }
        }

        ksort($tests);

        return $tests;
    }

    /**
     * @param  array<string, class-string>  $tests
     * @return list<string>
     */
    protected function classesOffTheirNearestBase(array $tests): array
    {
        $offenders = [];

        foreach ($tests as $path => $class) {
            $parent = (string) get_parent_class($class);
            $bases = $this->nearestBasesFor($path);

            if (! in_array($parent, $bases, true)) {
                $offenders[] = "{$class} extends {$parent}; nearest base is ".(implode(' | ', $bases) ?: 'none');
            }
        }

        return $offenders;
    }

    /**
     * The *TestCase classes of the closest folder, from the file's own up to its module, that holds any.
     *
     * @return list<string>
     */
    protected function nearestBasesFor(string $path): array
    {
        for ($folder = dirname($path); $folder !== self::FEATURE_ROOT && $folder !== '.'; $folder = dirname($folder)) {
            $bases = glob(base_path($folder).'/*TestCase.php') ?: [];

            if ($bases !== []) {
                $module = substr($folder, strlen(self::FEATURE_ROOT) + 1);

                return array_map(fn (string $base): string => $this->classOf($module.'/'.basename($base)), $bases);
            }
        }

        return [];
    }

    /**
     * The class a file under tests/Feature declares, from its path relative to that folder.
     */
    protected function classOf(string $relative): string
    {
        return 'Tests\\Feature\\'.str_replace(['/', '.php'], ['\\', ''], $relative);
    }
}
