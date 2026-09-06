#!/bin/sh
# Sec 2.2 Rule 11 (coding-conventions-plan; OQ-1-177): a test states its expected
# HTTP status itself - a named Laravel assert (assertUnprocessable(), ...) when
# the status is asserted directly, a Response::HTTP_* constant when it travels
# as a value (a helper parameter, a DataProvider row). Two bans inside
# assertStatus( on tests/**/*.php:
#  M1 derived  - the status computed from production (`->status()`): the
#                expectation moves with the mutation, so the test cannot fail
#  M2 bare int - a three-digit literal (use the named assert)
# Comment lines are excluded (call-form only). Every matcher AND the comment
# filter are self-tested at startup on a planted hit and a planted miss; a
# matcher that cannot match aborts with exit 2 and NO summary line.
# Shared by lefthook pre-commit (staged files) and the CI quality job (whole
# tree). ALWAYS ends with the summary line CI parses; `tests:` counts files
# actually inspected.
# Usage: sh scripts/check-status-assertions.sh [file...]   (no args = scan tests/)
derived='assertStatus\([^)]*->status\(\)'
bare='assertStatus\([[:space:]]*[0-9]{3}[[:space:]]*\)'
# applied to `grep -n` output (line:content), so the anchor sits after the number
comment='^[0-9]+:[[:space:]]*(\*|//)'

self_test() {
  # $1 label, $2 pattern, $3 a line the pattern MUST match, $4 a line it must NOT match
  if ! printf '%s\n' "$3" | grep -qE "$2"; then
    echo "self-test failed - $1 cannot match its positive control"
    exit 2
  fi
  if printf '%s\n' "$4" | grep -qE "$2"; then
    echo "self-test failed - $1 matches its negative control"
    exit 2
  fi
}
self_test 'M1 derived' "$derived" \
  '        $response->assertStatus($code->status());' \
  '        $response->assertStatus($expectedStatus);'
self_test 'M1 derived (enum form)' "$derived" \
  '            ->assertStatus(ApiErrorCode::InviteInvalid->status());' \
  '        $this->assertSame(500, ApiErrorCode::HttpError->status());'
self_test 'M2 bare int' "$bare" \
  '        $response->assertStatus(422);' \
  '        $response->assertStatus(Response::HTTP_OK);'
self_test 'M2 bare int (spaced)' "$bare" \
  '        ->assertStatus( 201 );' \
  '        $response->assertStatus($status);'
self_test 'comment filter (docblock)' "$comment" \
  '12:     * assertStatus(422) in a docblock' \
  '12:        $response->assertStatus(422);'
self_test 'comment filter (// form)' "$comment" \
  '12:        // $response->assertStatus(422);' \
  '12:        $this->assertRefusedWith($response, Response::HTTP_OK, ApiErrorCode::InviteInvalid);'

violations=0
scanned=0

report() {
  # $1 file, $2 pattern, $3 label
  hits=$(grep -nE "$2" "$1" | grep -vE "$comment")
  [ -n "$hits" ] || return 0
  n=$(printf '%s\n' "$hits" | wc -l | tr -d ' ')
  printf '%s\n' "$hits" | sed "s|^|sec-2.2 Rule 11 violation - assertStatus( fed by $3: $1:|"
  violations=$((violations + n))
}

inspect() {
  f=$1
  [ -f "$f" ] || return 0
  case "$f" in *.php) ;; *) return 0 ;; esac
  case "$f" in tests/*|./tests/*|*/tests/*) ;; *) return 0 ;; esac
  scanned=$((scanned + 1))
  report "$f" "$derived" 'a status read from production (->status())'
  report "$f" "$bare" 'a bare integer (use the named assert)'
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
echo "Status-assertion check: ${violations} violation(s); tests: ${scanned} file(s) scanned"
[ "$violations" -eq 0 ]
