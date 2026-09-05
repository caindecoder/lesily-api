#!/bin/sh
# Sec 2.15.C (coding-conventions-plan): the canonical write-path chain's
# layer-skip bans, call-form only - comment lines are excluded, so a docblock
# that merely names DB::transaction never fires. Three checks:
#  C1 controllers never ::retrieve( / DB::x( / ->persist( - they translate
#     and present only (the chain starts at the command).
#  C2 DB::transaction( lives in UseCases/Interactors/ only - interactors own
#     the transaction.
#  C3 reactors never write (ban 4 - side effects only, Pattern A): no
#     aggregate retrieve/persist, no DB facade, no Eloquent write verb.
# Every pattern is self-tested against a literal it must match before any file
# is read: a pattern that cannot match (a broken regex makes grep exit 2 and
# print nothing) would otherwise report a clean tree. Shared by lefthook
# pre-commit (staged app/ files) and the CI quality job (whole tree). ALWAYS
# ends with the summary line CI parses - except on a self-test abort, which
# exits 2 with no summary so CI's liveness proof fails too; `scanned` counts
# files actually inspected; `violations` counts offending lines.
# Usage: sh scripts/check-write-path-chain.sh [file...]   (no args = scan app/)
violations=0
scanned=0
comment='^[0-9]+:[[:space:]]*(\*|//)'
aggregate_io='::retrieve\(|->persist\('
db_facade='DB::[A-Za-z_]+[[:space:]]*\('
eloquent_write='->(save|update|delete|forceDelete|insert|create|forceFill|increment|decrement|touch)\('
static_write='::(create|insert|upsert|updateOrCreate|firstOrCreate|forceCreate)\('
layer_skip="$aggregate_io|$db_facade"
transaction='DB::transaction[[:space:]]*\('
reactor_write="$aggregate_io|$db_facade|$eloquent_write|$static_write"

self_test() {
  printf '%s\n' "$2" | grep -qE "$1" && return 0
  echo "Write-path chain check aborted - pattern cannot match its control '$2': $1"
  exit 2
}
self_test "$layer_skip" 'UserAggregate::retrieve($id)->persist();'
self_test "$layer_skip" 'DB::transaction(fn () => 1);'
self_test "$transaction" 'DB::transaction(fn () => 1);'
self_test "$reactor_write" 'User::query()->update([]);'
self_test "$reactor_write" 'User::create([]);'

report() {
  hits=$(grep -nE "$3" "$1" | grep -vE "$comment")
  [ -n "$hits" ] || return 0
  printf '%s\n' "$hits"
  echo "sec-2.15 violation - $2: $1"
  violations=$((violations + $(printf '%s\n' "$hits" | wc -l)))
}

inspect() {
  f=$1
  [ -f "$f" ] || return 0
  case "$f" in *.php) ;; *) return 0 ;; esac
  case "$f" in app/*|./app/*|*/app/*) ;; *) return 0 ;; esac
  scanned=$((scanned + 1))
  case "$f" in
    */IO/Http/Controllers/*) report "$f" "controller layer-skip (controllers translate and present only)" "$layer_skip" ;;
  esac
  case "$f" in
    */UseCases/Interactors/*) ;;
    *) report "$f" "DB::transaction outside UseCases/Interactors" "$transaction" ;;
  esac
  case "$f" in
    */Reactors/*) report "$f" "reactor write (reactors are side effects only - ban 4)" "$reactor_write" ;;
  esac
}

if [ "$#" -gt 0 ]; then
  for f in "$@"; do inspect "$f"; done
else
  OLDIFS=$IFS
  IFS='
'
  for f in $(find app -name '*.php'); do inspect "$f"; done
  IFS=$OLDIFS
fi
echo "Write-path chain check: ${violations} violation(s); ${scanned} file(s) scanned"
[ "$violations" -eq 0 ]
