#!/bin/sh
# Amendment AQ (/tdd-red s187; coding-conventions-plan sec 2.15.B.2; OQ-1-172 design P1):
# validation lives at the HTTP boundary only. Three checks over app/:
#  1. every Command referenced from app/**/IO/Http/Controllers/** defines rules()
#  2. no Command::from( inside a controller (a controller never bypasses rules())
#  3. no Command::validateAndCreate( outside IO/Http (internal callers build via
#     ::from(); internal-only commands carry no rules())
# The CI quality job runs this REPORT-ONLY until OQ-1-172 T7: check 3 fires on the
# 8 legacy internal validateAndCreate sites (design sec 6.1.1 table B) that T4-T6
# clear; T7 flips the CI step to gating. The script ALWAYS ends with the summary line
# "AQ placement check: ..." - CI greps it as the liveness proof (s144 rule 2).
# Usage: sh scripts/check-command-validation-placement.sh   (from the repo root)
[ -d app ] || { echo "AQ check aborted - no app/ directory (run from the repo root)"; exit 2; }
fail=0
violations=0
checked=0

violation() {
  echo "AQ violation - $1"
  violations=$((violations + ${2:-1}))
  fail=1
}

# Prints the offending lines and reports one violation per site.
violation_per_site() {
  sites=$1
  site_count=$(echo "$sites" | wc -l | tr -d ' ')
  echo "$sites"
  violation "$2 ($site_count site(s))" "$site_count"
}

check_controller_commands_define_rules() {
  for controller in $(grep -rlE '[A-Za-z]+Command\b' app --include='*Controller.php'); do
    for cls in $(grep -oE '[A-Za-z]+Command\b' "$controller" | sort -u); do
      src=$(find app -name "$cls.php" | head -1)
      if [ -z "$src" ]; then
        echo "AQ check skipped - no source file for $cls (referenced from $controller)"
        continue
      fi
      checked=$((checked + 1))
      grep -q 'function rules(' "$src" || violation "HTTP-boundary command without rules(): $cls (referenced from $controller)"
    done
  done
}

check_no_from_in_controllers() {
  hits=$(grep -rnE 'Command::from\(' app --include='*.php' | grep '/IO/Http/Controllers/')
  [ -z "$hits" ] || violation_per_site "$hits" "::from( in a controller"
}

check_no_validate_outside_http_boundary() {
  hits=$(grep -rnE 'Command::validateAndCreate\(' app --include='*.php' | grep -v '/IO/Http/')
  [ -z "$hits" ] || violation_per_site "$hits" "validateAndCreate outside the HTTP boundary"
}

check_controller_commands_define_rules
check_no_from_in_controllers
check_no_validate_outside_http_boundary
echo "AQ placement check: $violations violation(s); $checked controller-referenced command(s) checked"
exit $fail
