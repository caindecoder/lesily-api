#!/bin/sh
# OQ-1-181 (coding-conventions-plan sec 2.1): the database test trait lives in
# tests/TestCase.php ONLY. A per-class `use RefreshDatabase;` (or any sibling DB
# trait - alone, in a combined `use A, RefreshDatabase;` line, on a wrapped
# continuation line, opening a conflict-resolution block `use RefreshDatabase {`,
# or as a leftover import - plain, aliased `... as X;`, or anywhere inside a
# grouped `{A, B};` import) would shadow the base class's migrateDatabases()
# override with the trait's own copy - no failure, just the old migrate:fresh
# per Infection worker for that class. The pattern is self-tested on a planted
# hit and a planted miss before any file is read: behind `if grep`, a pattern
# that cannot match (a broken regex makes grep exit 2 and print nothing) would
# otherwise report a clean tree. The path classifier is self-tested too - a
# loosened exemption would wave every module *TestCase.php through - and a
# whole-tree scan that never reaches tests/TestCase.php aborts (its scan root
# is wrong). Shared by lefthook pre-commit (staged files)
# and the CI quality job (whole tree). ALWAYS ends with the summary line CI
# parses - except on a self-test abort, which exits 2 with NO summary so CI's
# liveness proof fails too. `scanned` counts files actually inspected.
# Usage: sh scripts/check-db-trait-placement.sh [file...]   (no args = scan tests/)
pattern='^[[:space:]]*(use\b[^;]*)?\b(RefreshDatabase|LazilyRefreshDatabase|DatabaseTransactions|DatabaseMigrations|DatabaseTruncation)\b[[:space:]]*([;,{}]|as[[:space:]])'

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
self_test 'single use' "$pattern" \
  '    use RefreshDatabase;' \
  '    use WithFaker;'
self_test 'grouped use' "$pattern" \
  '    use CreatesApplication, LazilyRefreshDatabase;' \
  '    use CreatesApplication, WithFaker;'
self_test 'import' "$pattern" \
  'use Illuminate\Foundation\Testing\RefreshDatabase;' \
  'use Illuminate\Foundation\Testing\RefreshDatabaseState;'
self_test 'fully-qualified in-class use' "$pattern" \
  '    use \Illuminate\Foundation\Testing\DatabaseTransactions;' \
  '        RefreshDatabaseState::$migrated = true;'
self_test 'wrapped continuation item' "$pattern" \
  '        DatabaseMigrations,' \
  ' * base test case supplies `RefreshDatabase`, so any test added'
self_test 'conflict-resolution block' "$pattern" \
  '    use RefreshDatabase {' \
  '     * field per test; the fixed invoice number is safe per-test (RefreshDatabase'
self_test 'aliased import' "$pattern" \
  'use Illuminate\Foundation\Testing\RefreshDatabase as Refresh;' \
  '    // use RefreshDatabase;'
self_test 'group import (trait first)' "$pattern" \
  'use Illuminate\Foundation\Testing\{RefreshDatabase, WithFaker};' \
  'use Illuminate\Foundation\Testing\{WithFaker};'
self_test 'group import (trait last)' "$pattern" \
  'use Illuminate\Foundation\Testing\{WithFaker, DatabaseTruncation};' \
  '        $this->refreshDatabase();'

# Sets $place from the path alone: base (tests/TestCase.php, the one file that
# may declare the trait) | tests (any other file under tests/) | outside.
place_of() {
  case "$1" in
    tests/TestCase.php|./tests/TestCase.php|*/tests/TestCase.php) place=base ;;
    tests/*|./tests/*|*/tests/*) place=tests ;;
    *) place=outside ;;
  esac
}
expect_place() {
  # $1 path, $2 the place it must classify as
  place_of "$1"
  if [ "$place" != "$2" ]; then
    echo "self-test failed - $1 classifies as $place, expected $2"
    exit 2
  fi
}
expect_place tests/TestCase.php base
expect_place ./tests/TestCase.php base
expect_place /repo/lesily-api/tests/TestCase.php base
expect_place tests/Feature/Auth/AuthTestCase.php tests
expect_place tests/Concerns/InducesDatabaseFailures.php tests
expect_place ./tests/Unit/ExampleTest.php tests
expect_place app/Providers/AppServiceProvider.php outside
expect_place /tmp/PlainClass.php outside

violations=0
scanned=0
reached_base=0
inspect() {
  f=$1
  [ -f "$f" ] || return 0
  place_of "$f"
  case "$place" in
    base) reached_base=1; return 0 ;;
    outside) return 0 ;;
  esac
  scanned=$((scanned + 1))
  if grep -nE -- "$pattern" "$f"; then
    echo "sec-2.1 violation - a database test trait declared or imported outside tests/TestCase.php: $f"
    violations=$((violations + 1))
  fi
}
if [ "$#" -gt 0 ]; then
  for f in "$@"; do inspect "$f"; done
else
  OLDIFS=$IFS
  IFS='
'
  for f in $(find tests -name '*.php'); do inspect "$f"; done
  IFS=$OLDIFS
  if [ "$reached_base" -eq 0 ]; then
    echo "self-test failed - the whole-tree scan never reached tests/TestCase.php (wrong scan root?)"
    exit 2
  fi
fi
echo "DB trait placement check: ${violations} violation(s); ${scanned} file(s) scanned"
[ "$violations" -eq 0 ]
