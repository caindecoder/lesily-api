#!/bin/sh
# Sec 2.14 (coding-conventions-plan): behavior-named test classes only.
# Two rule classes:
#  1. Artifact-named test files (XAggregateTest, XProjectionTest, XProjectorTest,
#     XReactorTest, XEntityTest, XModelTest) require an @testclass-carveout: docblock line.
#  2. Test-PARTITION-named files (XSadPathTest, XHappyPathTest, XEdgeCaseTest,
#     XErrorPathTest, XNegativeTest) are banned outright - no carve-out escape,
#     because no partition name is ever a behavior name.
# Shared by lefthook pre-commit (staged files) and CI quality job (changed files).
# Usage: check-test-class-naming.sh <file...>
fail=0
for f in "$@"; do
  [ -f "$f" ] || continue
  case "$f" in
    *SadPathTest.php|*HappyPathTest.php|*EdgeCaseTest.php|*ErrorPathTest.php|*NegativeTest.php)
      echo "sec-2.14 violation - test class named for a test partition, not a behavior: $f"
      fail=1
      ;;
    *AggregateTest.php|*ProjectionTest.php|*ProjectorTest.php|*ReactorTest.php|*EntityTest.php|*ModelTest.php)
      if ! grep -q "@testclass-carveout:" "$f"; then
        echo "sec-2.14 violation - artifact-named test class without @testclass-carveout: $f"
        fail=1
      fi
      ;;
  esac
done
exit $fail
