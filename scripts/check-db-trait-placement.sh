#!/bin/sh
# OQ-1-181 (coding-conventions-plan sec 2.1): the database test trait lives in
# tests/TestCase.php ONLY. A per-class `use RefreshDatabase;` (or any sibling DB
# trait - alone, in a combined `use A, RefreshDatabase;` line, on a wrapped
# continuation line, or as a leftover import) would shadow the base class's
# migrateDatabases() override with the trait's own copy - no failure, just the
# old migrate:fresh per Infection worker for that class. Shared by lefthook
# pre-commit (staged files) and the CI quality job (whole tree). ALWAYS ends
# with the summary line CI parses; `scanned` counts files actually inspected.
# Usage: sh scripts/check-db-trait-placement.sh [file...]   (no args = scan tests/)
violations=0
scanned=0
pattern='^[[:space:]]*(use\b[^;]*)?\b(RefreshDatabase|LazilyRefreshDatabase|DatabaseTransactions|DatabaseMigrations|DatabaseTruncation)\b[[:space:]]*[;,]'
inspect() {
  f=$1
  [ -f "$f" ] || return 0
  case "$f" in *tests/TestCase.php) return 0 ;; esac
  case "$f" in tests/*|./tests/*|*/tests/*) ;; *) return 0 ;; esac
  scanned=$((scanned + 1))
  if grep -nE "$pattern" "$f"; then
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
