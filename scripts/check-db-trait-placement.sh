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
# otherwise report a clean tree. Shared by lefthook pre-commit (staged files)
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

violations=0
scanned=0
inspect() {
  f=$1
  [ -f "$f" ] || return 0
  case "$f" in *tests/TestCase.php) return 0 ;; esac
  case "$f" in tests/*|./tests/*|*/tests/*) ;; *) return 0 ;; esac
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
fi
echo "DB trait placement check: ${violations} violation(s); ${scanned} file(s) scanned"
[ "$violations" -eq 0 ]
