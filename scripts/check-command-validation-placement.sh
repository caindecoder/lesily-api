#!/bin/sh
# Amendment AQ (/tdd-red s187; coding-conventions-plan sec 2.15.B.2; OQ-1-172 design P1):
# validation lives at the HTTP boundary only. Three checks over app/:
#  1. every Command referenced from app/**/IO/Http/Controllers/*Controller.php defines rules()
#  2. no Command::from( inside a controller (a controller never bypasses rules())
#  3. no Command::validateAndCreate( outside IO/Http (internal callers build via
#     ::from(); internal-only commands carry no rules())
# Comment lines are excluded from checks 2 and 3 (the ci.yml sec-2.15 house filter);
# a command whose source file is missing or ambiguous is SKIPPED loudly and counted.
# The CI quality job runs this REPORT-ONLY until OQ-1-172 T7: check 3 fires on the
# 8 legacy internal validateAndCreate sites (design sec 6.1.1 table B) that T4-T6
# clear; CI bounds the tolerated count at that baseline and T7 flips the step to gating.
# The script ALWAYS ends with the summary line "AQ placement check: ..." - CI parses it
# as the liveness proof (s144 rule 2) and asserts that check 1 inspected something.
# Usage: sh scripts/check-command-validation-placement.sh   (from the repo root)
[ -d app ] || { echo "AQ check aborted - no app/ directory (run from the repo root)"; exit 2; }
fail=0
violations=0
checked=0
skipped=0
comment_lines=':[0-9]+:\s*(\*|//)'
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
    for cls in $(grep -oE '\b[A-Z][A-Za-z]*Command\b' "$controller" | sort -u); do
      src=$(find app -name "$cls.php")
      case "$src" in
        '') skip "no source file for $cls (referenced from $controller)"; continue ;;
        *"$newline"*) skip "ambiguous source for $cls (referenced from $controller): $(printf '%s' "$src" | tr '\n' ' ')"; continue ;;
      esac
      checked=$((checked + 1))
      grep -qE '^\s*public static function rules\(' "$src" || violation "HTTP-boundary command without rules(): $cls (referenced from $controller)"
    done
  done
  unset IFS
}

check_no_from_in_controllers() {
  sites=$(grep -rnE 'Command::from\(' app --include='*.php' | grep '/IO/Http/Controllers/' | grep -vE "$comment_lines")
  [ -z "$sites" ] || violation_per_site "$sites" "::from( in a controller"
}

check_no_validate_outside_http_boundary() {
  sites=$(grep -rnE 'Command::validateAndCreate\(' app --include='*.php' | grep -v '/IO/Http/' | grep -vE "$comment_lines")
  [ -z "$sites" ] || violation_per_site "$sites" "validateAndCreate outside the HTTP boundary"
}

check_controller_commands_define_rules
check_no_from_in_controllers
check_no_validate_outside_http_boundary
echo "AQ placement check: $violations violation(s); $checked controller-referenced command(s) checked; $skipped skipped"
exit $fail
