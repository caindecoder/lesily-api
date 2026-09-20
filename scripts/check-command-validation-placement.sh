#!/bin/sh
# Amendment AQ (/tdd-red s187; coding-conventions-plan sec 2.15.B.2; OQ-1-172 design P1):
# validation lives at the HTTP boundary only. Three checks over app/:
#  1. every Command referenced from app/**/IO/Http/Controllers/*Controller.php defines rules()
#  2. no Command::from( inside a controller (a controller never bypasses rules())
#  3. no Command::validateAndCreate( outside IO/Http (internal callers build via
#     ::from(); internal-only commands carry no rules())
# Comment lines (`*`, `/*`, `//`, `#` openers) are excluded from checks 2 and 3;
# the filter is anchored to the `file:line:` prefix grep -rn prints, so a code
# line that merely contains a `:NN:` pair is never swallowed. A command whose
# source file is missing or ambiguous is SKIPPED loudly and counted.
# Every matcher - the comment filter included - is self-tested on a planted hit
# and a planted miss before any file is read: checks 2 and 3 sit behind a
# `grep -v` pipeline, where a broken regex makes grep exit 2, print nothing and
# report a clean tree.
# The CI quality job runs this GATING (since OQ-1-172 T7): any violation of any
# check - and any skipped command - fails the step. Scope is app/ only -
# factories and tests are convention territory for the review, not this guard.
# The script ALWAYS ends with the summary line "AQ placement check: ..." - CI
# parses it as the liveness proof and asserts that check 1 inspected something -
# except on a self-test abort, which exits 2 with NO summary so CI fails too.
# Usage: sh scripts/check-command-validation-placement.sh   (from the repo root)
[ -d app ] || { echo "AQ check aborted - no app/ directory (run from the repo root)"; exit 2; }
# applied to `grep -rn` output (file:line:content), so the anchor sits after the prefix
comment_lines='^[^:]+:[0-9]+:[[:space:]]*(\*|/\*|//|#)'
command_name='\b[A-Z][A-Za-z0-9_]*Command\b'
defines_rules='^[[:space:]]*public static function rules\('
from_call='Command::from\('
validate_call='Command::validateAndCreate\('

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
self_test 'comment filter (docblock)' "$comment_lines" \
  'app/X.php:12: * FooCommand::validateAndCreate(' \
  'app/X.php:12:        $command = FooCommand::validateAndCreate($data);'
self_test 'comment filter (// form)' "$comment_lines" \
  'app/X.php:12:    // FooCommand::validateAndCreate(' \
  "app/X.php:12:        \$command = FooCommand::validateAndCreate(['at' => '09:30: // note']);"
self_test 'comment filter (/* form)' "$comment_lines" \
  'app/X.php:12:    /* FooCommand::from( */' \
  'app/X.php:12:        $command = FooCommand::from($data);'
self_test 'comment filter (# form)' "$comment_lines" \
  'app/X.php:12:    # FooCommand::from(' \
  'app/X.php:12:        return FooCommand::from($data) * 2; // trailing note'
self_test 'command name' "$command_name" \
  '        $command = UpdateCompanyCommand::fromRequest($request);' \
  '        $response = $interactor->execute($command);'
self_test 'rules() definition' "$defines_rules" \
  '    public static function rules(): array' \
  '    public function rules(): array'
self_test '::from( call' "$from_call" \
  '        $command = FooCommand::from($request->all());' \
  '        $command = FooCommand::validateAndCreate($request->all());'
self_test '::validateAndCreate( call' "$validate_call" \
  '        $command = FooCommand::validateAndCreate($request->all());' \
  '        $command = FooCommand::from($request->all());'

fail=0
violations=0
checked=0
skipped=0
newline='
'

violation() {
  echo "AQ violation - $1"
  violations=$((violations + ${2:-1}))
  fail=1
}

# Prints the offending lines and reports one violation per site.
violation_per_site() {
  sites=$1
  site_count=$(printf '%s\n' "$sites" | wc -l | tr -d ' ')
  printf '%s\n' "$sites"
  violation "$2 ($site_count site(s))" "$site_count"
}

skip() {
  echo "AQ check skipped - $1"
  skipped=$((skipped + 1))
}

check_controller_commands_define_rules() {
  IFS=$newline
  for controller in $(find app -path '*/IO/Http/Controllers/*' -name '*Controller.php'); do
    for cls in $(grep -oE -e "$command_name" "$controller" | sort -u); do
      src=$(find app -name "$cls.php")
      case "$src" in
        '') skip "no source file for $cls (referenced from $controller)"; continue ;;
        *"$newline"*) skip "ambiguous source for $cls (referenced from $controller): $(printf '%s' "$src" | tr '\n' ' ')"; continue ;;
      esac
      checked=$((checked + 1))
      grep -qE -e "$defines_rules" "$src" || violation "HTTP-boundary command without rules(): $cls (referenced from $controller)"
    done
  done
  unset IFS
}

check_no_from_in_controllers() {
  sites=$(grep -rnE --include='*.php' -e "$from_call" app | grep '/IO/Http/Controllers/' | grep -vE -e "$comment_lines")
  [ -z "$sites" ] || violation_per_site "$sites" "::from( in a controller"
}

check_no_validate_outside_http_boundary() {
  sites=$(grep -rnE --include='*.php' -e "$validate_call" app | grep -v '/IO/Http/' | grep -vE -e "$comment_lines")
  [ -z "$sites" ] || violation_per_site "$sites" "validateAndCreate outside the HTTP boundary"
}

check_controller_commands_define_rules
check_no_from_in_controllers
check_no_validate_outside_http_boundary
echo "AQ placement check: $violations violation(s); $checked controller-referenced command(s) checked; $skipped skipped"
exit $fail
