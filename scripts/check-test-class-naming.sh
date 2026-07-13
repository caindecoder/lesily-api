#!/bin/sh
# Sec 2.14 (coding-conventions-plan): behavior-named test classes only.
# Artifact-named test files (XAggregateTest, XProjectionTest, XProjectorTest,
# XReactorTest, XEntityTest, XModelTest) require an @testclass-carveout: docblock line.
# Shared by lefthook pre-commit (staged files) and CI quality job (changed files).
# Usage: check-test-class-naming.sh <file...>
fail=0
for f in "$@"; do
  [ -f "$f" ] || continue
  case "$f" in
    *AggregateTest.php|*ProjectionTest.php|*ProjectorTest.php|*ReactorTest.php|*EntityTest.php|*ModelTest.php)
      if ! grep -q "@testclass-carveout:" "$f"; then
        echo "sec-2.14 violation - artifact-named test class without @testclass-carveout: $f"
        fail=1
      fi
      ;;
  esac
done
exit $fail
