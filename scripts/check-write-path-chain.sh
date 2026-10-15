#!/bin/sh
# Sec 2.15.C (coding-conventions-plan): the canonical write-path chain's
# layer-skip bans, call-form only - leading comment lines (`*`, `/*`, `//`, `#`)
# are excluded, so a docblock that merely names DB::transaction never fires.
# Four checks:
#  C1 controllers never ::retrieve( / DB::x( / ->persist( - they translate
#     and present only (the chain starts at the command).
#  C2 DB::transaction( lives in UseCases/Interactors/ only - interactors own
#     the transaction.
#  C3 reactors never write (ban 4 made mechanical - "side effects only",
#     deliberately stricter than the cross-aggregate clause alone): no
#     aggregate retrieve/persist, no DB facade, no Eloquent / query-builder /
#     relation write verb (the named list, Quietly/OrFail variants included).
#  C4 an interactor that persists events does so inside its own
#     DB::transaction( - spatie's persist() opens none, so unwrapped a
#     projector failure leaves the event committed and the read model stale.
#     persistInTransaction( counts as a persist: it wraps only the event
#     insert, the projectors run after its commit. File-level: one violation
#     per file that calls a persist and carries no DB::transaction( at all;
#     a persist placed outside the closure in a file that also wraps is left
#     to review.
# Every matcher - the comment filter included - is self-tested before any file
# is read: a pattern that cannot match its control (a broken regex makes grep
# exit 2 and print nothing) or a filter that swallows a code line would
# otherwise report a clean tree. Shared by lefthook pre-commit (staged app/
# files) and the CI quality job (whole tree). ALWAYS ends with the summary line
# CI parses - except on a self-test abort, which exits 2 with no summary so
# CI's liveness proof fails too. `scanned` counts files actually inspected and
# the per-check populations (controllers / interactors / reactors, and the
# persisting interactors C4 judges) show each check still sees its layer - CI
# asserts every population is non-zero.
# Usage: sh scripts/check-write-path-chain.sh [file...]   (no args = scan app/)
violations=0
scanned=0
controllers=0
interactors=0
reactors=0
persisting=0
comment='^[0-9]+:[[:space:]]*(\*|/\*|//|#)'
aggregate_io='::retrieve[[:space:]]*\(|->persist[[:space:]]*\('
db_facade='DB::[A-Za-z_]+[[:space:]]*\('
eloquent_write='->(save|update|delete|forceDelete|restore|insert|insertGetId|insertOrIgnore|updateOrInsert|upsert|create|forceCreate|createMany|saveMany|push|forceFill|increment|decrement|touch|updateOrCreate|firstOrCreate|attach|detach|sync|syncWithoutDetaching|toggle|associate|dissociate)(Quietly|OrFail)?[[:space:]]*\('
static_write='::(create|forceCreate|insert|insertGetId|insertOrIgnore|updateOrInsert|upsert|updateOrCreate|firstOrCreate|destroy)(Quietly)?[[:space:]]*\('
layer_skip="$aggregate_io|$db_facade"
transaction='DB::transaction[[:space:]]*\('
persist_call='::persistInTransaction[[:space:]]*\(|->persist[[:space:]]*\('
reactor_write="$aggregate_io|$db_facade|$eloquent_write|$static_write"

abort() {
  echo "Write-path chain check aborted - $1"
  exit 2
}
must_match() {
  printf '%s\n' "$2" | grep -qE "$1" || abort "pattern cannot match its control '$2': $1"
}
must_not_match() {
  printf '%s\n' "$2" | grep -qE "$1" && abort "pattern swallows a code line '$2': $1"
}
must_match "$comment" '12: * DB::transaction('
must_match "$comment" '12:    // DB::transaction('
must_not_match "$comment" '12:        DB::transaction(fn () => 1);'
must_match "$layer_skip" 'UserAggregate::retrieve($id);'
must_match "$layer_skip" '$aggregate->persist();'
must_match "$layer_skip" 'DB::transaction(fn () => 1);'
must_match "$transaction" 'DB::transaction(fn () => 1);'
must_match "$reactor_write" '$aggregate->persist();'
must_match "$reactor_write" 'User::query()->update([]);'
must_match "$reactor_write" '$user->roles()->attach($id);'
must_match "$reactor_write" 'User::create([]);'
must_match "$persist_call" '$aggregate->persist();'
must_match "$persist_call" 'Agg::persistInTransaction($aggregate);'
must_not_match "$persist_call" '$this->persistWithoutApplyingToEventHandlers();'

# C4 reads stdin: has_call - a non-comment line calls $1; persists_unwrapped -
# a persist call and no DB::transaction( call. The persisting count uses
# has_call too, so the controls below cover it.
has_call() {
  grep -nE "$1" | grep -vE "$comment" | grep -q .
}
persists_unwrapped() {
  body=$(cat)
  printf '%s\n' "$body" | has_call "$persist_call" || return 1
  printf '%s\n' "$body" | has_call "$transaction" && return 1
  return 0
}
printf '%s\n' '$aggregate->persist();' | persists_unwrapped \
  || abort "C4 does not flag an unwrapped persist"
printf '%s\n' 'DB::transaction(fn () => $aggregate->persist());' | persists_unwrapped \
  && abort "C4 flags a wrapped persist"
printf '%s\n' '    // $aggregate->persist();' | persists_unwrapped \
  && abort "C4 flags a persist on a comment line"
printf '%s\n' '    // DB::transaction(fn () => 1);' '$aggregate->persist();' | persists_unwrapped \
  || abort "C4 counts a transaction on a comment line"

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
    */IO/Http/Controllers/*)
      controllers=$((controllers + 1))
      report "$f" "controller layer-skip (controllers translate and present only)" "$layer_skip"
      ;;
  esac
  case "$f" in
    */UseCases/Interactors/*)
      interactors=$((interactors + 1))
      has_call "$persist_call" < "$f" && persisting=$((persisting + 1))
      if persists_unwrapped < "$f"; then
        grep -nE "$persist_call" "$f" | grep -vE "$comment"
        echo "sec-2.15 violation - persist outside an interactor transaction (C4): $f"
        violations=$((violations + 1))
      fi
      ;;
    *) report "$f" "DB::transaction outside UseCases/Interactors" "$transaction" ;;
  esac
  case "$f" in
    */Reactors/*)
      reactors=$((reactors + 1))
      report "$f" "reactor write (reactors are side effects only - ban 4)" "$reactor_write"
      ;;
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
echo "Write-path chain check: ${violations} violation(s); ${scanned} file(s) scanned (controllers ${controllers}, interactors ${interactors}, reactors ${reactors}, persisting ${persisting})"
[ "$violations" -eq 0 ]
