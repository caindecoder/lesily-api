#!/bin/sh
# Sec 2.14 (coding-conventions-plan): behavior-named test classes only.
# Two rule classes:
#  1. Artifact-named test files require an @testclass-carveout: docblock line -
#     the six production-artifact suffixes (XAggregateTest, XProjectionTest,
#     XProjectorTest, XReactorTest, XEntityTest, XModelTest) and the Rule-B
#     carve-out categories whose artifact IS the behavior (XSeederTest,
#     XMailRenderTest, XCommandTest for console commands; a DTO-command
#     XCommandTest has no carve-out - Rule A bans it at review). The marker
#     must sit on a docblock line (` * @testclass-carveout: ...`); a string
#     literal or a // line does not count.
#  2. Test-PARTITION-named files (XSadPathTest, XHappyPathTest, XEdgeCaseTest,
#     XErrorPathTest, XNegativeTest) are banned outright - no carve-out escape,
#     because no partition name is ever a behavior name.
# Both matchers are self-tested before any file is read: every suffix must
# classify as its arm (a deleted or mistyped suffix would otherwise narrow the
# gate with nothing to notice it) and the carve-out regex must match a docblock
# line and must NOT match a // line or a string literal (a loosened regex would
# let any mention of the marker pass). Shared by lefthook pre-commit (staged
# files) and the CI quality job (whole tree). Only tests/**/*Test.php files are
# inspected. ALWAYS ends with the summary line CI parses - except on a
# self-test abort, which exits 2 with NO summary so CI's liveness proof fails
# too. `scanned` counts files actually inspected.
# Usage: sh scripts/check-test-class-naming.sh [file...]   (no args = scan tests/)
carveout='^[[:space:]]*\*[[:space:]]*@testclass-carveout:'

# Sets $kind from the file name alone: partition | artifact | plain.
# The partition arm comes first - a partition name keeps no carve-out escape.
kind_of() {
  case "$1" in
    *SadPathTest.php|*HappyPathTest.php|*EdgeCaseTest.php|*ErrorPathTest.php|*NegativeTest.php)
      kind=partition ;;
    *AggregateTest.php|*ProjectionTest.php|*ProjectorTest.php|*ReactorTest.php|*EntityTest.php|*ModelTest.php|*SeederTest.php|*MailRenderTest.php|*CommandTest.php)
      kind=artifact ;;
    *)
      kind=plain ;;
  esac
}

self_test() {
  # $1 label, $2 pattern, $3 a line the pattern MUST match, $4 a line it must NOT match
  if ! printf '%s\n' "$3" | grep -qE -- "$2"; then
    echo "self-test failed - $1 cannot match its positive control"
    exit 2
  fi
  if printf '%s\n' "$4" | grep -qE -- "$2"; then
    echo "self-test failed - $1 matches its negative control"
    exit 2
  fi
}
expect_kind() {
  # $1 file name, $2 the kind it must classify as
  kind_of "$1"
  if [ "$kind" != "$2" ]; then
    echo "self-test failed - $1 classifies as $kind, expected $2"
    exit 2
  fi
}
for name in XSadPathTest.php XHappyPathTest.php XEdgeCaseTest.php XErrorPathTest.php XNegativeTest.php; do
  expect_kind "$name" partition
done
for name in XAggregateTest.php XProjectionTest.php XProjectorTest.php XReactorTest.php XEntityTest.php XModelTest.php XSeederTest.php XMailRenderTest.php XCommandTest.php; do
  expect_kind "$name" artifact
done
expect_kind RegisterOwnerTest.php plain
expect_kind SadPathFinderTest.php plain
self_test 'carve-out marker (docblock line vs // line)' "$carveout" \
  ' * @testclass-carveout: seeder' \
  '// @testclass-carveout: seeder'
self_test 'carve-out marker (docblock line vs string literal)' "$carveout" \
  '     * @testclass-carveout: mail-render' \
  "    private string \$marker = '@testclass-carveout: seeder';"

violations=0
scanned=0

inspect() {
  f=$1
  [ -f "$f" ] || return 0
  case "$f" in *Test.php) ;; *) return 0 ;; esac
  case "$f" in tests/*|./tests/*|*/tests/*) ;; *) return 0 ;; esac
  scanned=$((scanned + 1))
  kind_of "$f"
  case "$kind" in
    partition)
      echo "sec-2.14 violation - test class named for a test partition, not a behavior: $f"
      violations=$((violations + 1))
      ;;
    artifact)
      if ! grep -qE -- "$carveout" "$f"; then
        echo "sec-2.14 violation - artifact-named test class without an @testclass-carveout: docblock line: $f"
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
