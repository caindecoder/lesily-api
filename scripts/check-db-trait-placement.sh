#!/bin/sh
# OQ-1-181 (coding-conventions-plan sec 2.1): the database test trait lives in
# tests/TestCase.php ONLY. A per-class `use RefreshDatabase;` (or any sibling DB
# trait, alone or in a combined `use A, RefreshDatabase;` line, or a leftover
# import) would shadow the base class's migrateDatabases() override with the
# trait's own copy - no failure, just the old migrate:fresh per Infection
# worker for that class. Shared by lefthook pre-commit (staged files) and the
# CI quality job (whole tree). ALWAYS ends with the summary line CI parses.
# Usage: sh scripts/check-db-trait-placement.sh [file...]   (no args = scan tests/)
if [ "$#" -gt 0 ]; then
  files="$*"
else
  files=$(find tests -name '*.php')
fi
violations=0
scanned=0
pattern='^[[:space:]]*use\b[^;]*\b(RefreshDatabase|LazilyRefreshDatabase|DatabaseTransactions|DatabaseMigrations|DatabaseTruncation)\b'
for f in $files; do
  [ -f "$f" ] || continue
  scanned=$((scanned + 1))
  [ "$f" = "tests/TestCase.php" ] && continue
  case "$f" in tests/*) ;; *) continue ;; esac
  if grep -nE "$pattern" "$f"; then
    echo "sec-2.1 violation - a database test trait declared or imported outside tests/TestCase.php: $f"
    violations=$((violations + 1))
  fi
done
echo "DB trait placement check: ${violations} violation(s); ${scanned} file(s) scanned"
[ "$violations" -eq 0 ]
