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
# Shared by lefthook pre-commit (staged app/ files) and the CI quality job
# (whole tree). ALWAYS ends with the summary line CI parses; `scanned` counts
# files actually inspected; `violations` counts offending lines.
# Usage: sh scripts/check-write-path-chain.sh [file...]   (no args = scan app/)
violations=0
scanned=0
comment='^[0-9]+:[[:space:]]*(\*|//)'
layer_skip='::retrieve\(|DB::[A-Za-z_]+[[:space:]]*\(|->persist\('
transaction='DB::transaction[[:space:]]*\('
reactor_write='::retrieve\(|->persist\(|DB::[A-Za-z_]+[[:space:]]*\(|->(save|update|delete|forceDelete|insert|create|forceFill|increment|decrement|touch)\(|::(create|insert|upsert|updateOrCreate|firstOrCreate|forceCreate)\('

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
