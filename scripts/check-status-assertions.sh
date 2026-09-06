#!/bin/sh
# Sec 2.2 Rule 11 (coding-conventions-plan; OQ-1-177 tests side, OQ-1-183
# production side): an HTTP status is spelled by name on BOTH sides of the
# double-entry, so neither side can drift silently into the other.
# tests/ - a named Laravel assert (assertUnprocessable(), ...) when the status
# is asserted directly, a Response::HTTP_* constant when it travels as a value
# (a helper parameter, a DataProvider row). Two bans inside assertStatus(:
#  M1 derived  - the status computed from production (`->status()`): the
#                expectation moves with the mutation, so the test cannot fail
#  M2 bare int - a three-digit literal (use the named assert)
# and two forms that reach the same tautology around assertStatus(:
#  M3 assigned - `$x = ...->status()` (the derived value parked in a variable,
#                then handed to assertStatus($x) or a provider row)
#  M4 expected - `assertSame(ApiErrorCode::X->status(), ...)` (the enum on the
#                EXPECTED side; `assertSame(500, ApiErrorCode::X->status())`
#                pins the arm with a literal and is allowed)
# app/ - an emitted status is a Response::HTTP_* constant, never a bare int:
#  A1 bare setStatusCode - `setStatusCode(201)` anywhere under app/
#  A2 bare match arm     - `=> 422,` inside a status map (ApiErrorCode.php,
#                          the only one today; a second map is added to
#                          status_maps below - no generic three-digit matcher
#                          over app/, where max:255 / Str::random(40) live)
# Comment lines are excluded (call-form only; //, /*, * and # openers - the
# check-write-path-chain.sh filter). Every matcher AND the comment filter are
# self-tested at startup on a planted hit and a planted miss; a matcher that
# cannot match aborts with exit 2 and NO summary line.
# Shared by lefthook pre-commit (status-assertions: staged tests/ files;
# status-constants: staged app/ files - the script routes each file to its
# population by path) and the CI quality job (whole tree). ALWAYS ends with
# the summary line CI parses; `tests:` and `app:` count the files actually
# inspected in each population.
# Usage: sh scripts/check-status-assertions.sh [file...]   (no args = scan tests/ and app/)
derived='assertStatus\([^)]*->status\(\)'
bare='assertStatus\([[:space:]]*[0-9]{3}[[:space:]]*\)'
assigned='=[[:space:]]*[^;]*->status\(\)'
expected_side='assert(Same|Equals)\([[:space:]]*ApiErrorCode::[A-Za-z]+->status\(\)'
bare_set_status='setStatusCode\([[:space:]]*[0-9]{3}[[:space:]]*\)'
bare_match_arm='=>[[:space:]]*[0-9]{3},'
# basenames of the production status maps A2 applies to (space-separated)
status_maps='ApiErrorCode.php'
# applied to `grep -n` output (line:content), so the anchor sits after the number
comment='^[0-9]+:[[:space:]]*(\*|/\*|//|#)'

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
self_test 'M3 assigned (enum form)' "$assigned" \
  '        $expected = ApiErrorCode::InviteInvalid->status();' \
  '        $this->assertSame(500, ApiErrorCode::HttpError->status());'
self_test 'M3 assigned (variable form)' "$assigned" \
  '        $expected = $code->status();' \
  '        $response->assertStatus($expectedStatus);'
self_test 'M4 expected side' "$expected_side" \
  '        $this->assertSame(ApiErrorCode::InviteInvalid->status(), $response->status());' \
  '        $this->assertSame($unknownEmail->status(), $wrongPassword->status());'
self_test 'A1 bare setStatusCode' "$bare_set_status" \
  '                ->response($request)->setStatusCode(201);' \
  '                ->response($request)->setStatusCode(Response::HTTP_CREATED);'
self_test 'A1 bare setStatusCode (spaced)' "$bare_set_status" \
  '        ->setStatusCode( 200 );' \
  '        ->setStatusCode($status);'
self_test 'A2 bare match arm' "$bare_match_arm" \
  '            self::InviteInvalid => 422,' \
  '            self::InviteInvalid => Response::HTTP_UNPROCESSABLE_ENTITY,'
self_test 'A2 bare match arm (fallback arm)' "$bare_match_arm" \
  '            self::HttpError => 500,' \
  "            'name' => ['required', 'max:255'],"
self_test 'comment filter (docblock)' "$comment" \
  '12:     * assertStatus(422) in a docblock' \
  '12:        $response->assertStatus(422);'
self_test 'comment filter (// form)' "$comment" \
  '12:        // $response->assertStatus(422);' \
  '12:        $this->assertRefusedWith($response, Response::HTTP_OK, ApiErrorCode::InviteInvalid);'
self_test 'comment filter (/* form)' "$comment" \
  '12:        /* $response->assertStatus(422); */' \
  '12:        $expected = ApiErrorCode::InviteInvalid->status();'
self_test 'comment filter (# form)' "$comment" \
  '12:        # $response->assertStatus(422);' \
  '12:        $this->assertSame(ApiErrorCode::InviteInvalid->status(), $response->status());'
self_test 'comment filter (app forms)' "$comment" \
  '12:        // ->setStatusCode(201);' \
  '12:                ->response($request)->setStatusCode(201);'

violations=0
scanned_tests=0
scanned_app=0

report() {
  # $1 file, $2 pattern, $3 label
  hits=$(grep -nE "$2" "$1" | grep -vE "$comment")
  [ -n "$hits" ] || return 0
  n=$(printf '%s\n' "$hits" | wc -l | tr -d ' ')
  printf '%s\n' "$hits" | sed "s|^|sec-2.2 Rule 11 violation - $3: $1:|"
  violations=$((violations + n))
}

inspect_test() {
  scanned_tests=$((scanned_tests + 1))
  report "$1" "$derived" 'M1 assertStatus( fed by a status read from production (->status())'
  report "$1" "$bare" 'M2 assertStatus( fed by a bare integer (use the named assert)'
  report "$1" "$assigned" 'M3 a status read from production and parked in a variable (state it as a named assert or a Response::HTTP_* constant)'
  report "$1" "$expected_side" 'M4 the enum on the expected side of assertSame/assertEquals'
}

inspect_app() {
  scanned_app=$((scanned_app + 1))
  report "$1" "$bare_set_status" 'A1 setStatusCode( fed by a bare integer (use a Response::HTTP_* constant)'
  case " $status_maps " in
    *" $(basename "$1") "*)
      report "$1" "$bare_match_arm" 'A2 a status-map arm returning a bare integer (use a Response::HTTP_* constant)' ;;
  esac
}

inspect() {
  f=$1
  [ -f "$f" ] || return 0
  case "$f" in *.php) ;; *) return 0 ;; esac
  case "$f" in
    tests/*|./tests/*|*/tests/*) inspect_test "$f" ;;
    app/*|./app/*|*/app/*) inspect_app "$f" ;;
  esac
}

if [ "$#" -gt 0 ]; then
  for f in "$@"; do inspect "$f"; done
else
  OLDIFS=$IFS
  IFS='
'
  for f in $(find tests app -name '*.php'); do inspect "$f"; done
  IFS=$OLDIFS
fi
populations="tests: ${scanned_tests} file(s) scanned; app: ${scanned_app} file(s) scanned"
echo "Status-assertion check: ${violations} violation(s); ${populations}"
[ "$violations" -eq 0 ]
