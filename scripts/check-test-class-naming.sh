#!/bin/sh
# Sec 2.14 (coding-conventions-plan): behavior-named test classes only.
# Two rule classes:
#  1. Artifact-named test files require an @testclass-carveout: docblock line -
#     the six production-artifact suffixes (XAggregateTest, XProjectionTest,
#     XProjectorTest, XReactorTest, XEntityTest, XModelTest) and the Rule-B
#     carve-out categories whose artifact IS the behavior (XSeederTest,
#     XMailRenderTest, XCommandTest for console commands; a DTO-command
#     XCommandTest has no carve-out - Rule A bans it at review).
#  2. Test-PARTITION-named files (XSadPathTest, XHappyPathTest, XEdgeCaseTest,
#     XErrorPathTest, XNegativeTest) are banned outright - no carve-out escape,
#     because no partition name is ever a behavior name.
# Shared by lefthook pre-commit (staged files) and the CI quality job (whole
# tree). ALWAYS ends with the summary line CI parses; `scanned` counts files
# actually inspected.
# Usage: sh scripts/check-test-class-naming.sh [file...]   (no args = scan tests/)
violations=0
scanned=0

inspect() {
  f=$1
  [ -f "$f" ] || return 0
  scanned=$((scanned + 1))
  case "$f" in
    *SadPathTest.php|*HappyPathTest.php|*EdgeCaseTest.php|*ErrorPathTest.php|*NegativeTest.php)
      echo "sec-2.14 violation - test class named for a test partition, not a behavior: $f"
      violations=$((violations + 1))
      ;;
    *AggregateTest.php|*ProjectionTest.php|*ProjectorTest.php|*ReactorTest.php|*EntityTest.php|*ModelTest.php|*SeederTest.php|*MailRenderTest.php|*CommandTest.php)
      if ! grep -q "@testclass-carveout:" "$f"; then
        echo "sec-2.14 violation - artifact-named test class without @testclass-carveout: $f"
        violations=$((violations + 1))
      fi
      ;;
  esac
}

if [ "$#" -gt 0 ]; then
  for f in "$@"; do inspect "$f"; done
else
  OLDIFS=$IFS
  IFS='
'
  for f in $(find tests -name '*Test.php'); do inspect "$f"; done
  IFS=$OLDIFS
fi
echo "Test-class naming check: ${violations} violation(s); ${scanned} file(s) scanned"
[ "$violations" -eq 0 ]
