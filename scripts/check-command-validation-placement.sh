#!/bin/sh
# Amendment AQ (/tdd-red s187; coding-conventions-plan sec 2.15.B.2; OQ-1-172 design P1):
# validation lives at the HTTP boundary only. Four checks over app/:
#  1. every request DTO referenced from app/**/IO/Http/Controllers/*Controller.php
#     defines rules() - the *Command names a controller mentions plus every class
#     that `extends Data` (filters DTOs included; the population is read from the
#     code, never from a name suffix)
#  2. no <DataClass>::from( inside a controller (a controller never bypasses
#     rules(); an enum's ::from( is outside the population and never fires)
#  3. no ::validateAndCreate( on ANY receiver outside IO/Http - internal callers
#     build via ::from(), internal-only commands carry no rules() - except the
#     one sanctioned line shape: static:: / self:: fed straight by $request->,
#     in a file under UseCases/Commands/ (a Request-fed named constructor counts
#     as the HTTP boundary, sec 2.15.B.2)
#  4. those Request-fed named constructors are called from IO/Http only, and a
#     file carrying the sanctioned line declares such a constructor
# Comment lines (`*`, `/*`, `//`, `#` openers) are excluded from checks 2-4; the
# filters are anchored to the `file:line:` prefix grep -rn prints, so a code
# line that merely contains a `:NN:` pair or a path fragment is never swallowed.
# A request DTO whose source file is missing or ambiguous is SKIPPED loudly and
# counted.
# Every matcher - filters, templates and the name extractor included - is
# self-tested on a planted hit and a planted miss before any file is read:
# checks 2-4 sit behind `grep -v` pipelines, where a broken regex makes grep
# exit 2, print nothing and report a clean tree.
# The CI quality job and the lefthook command-validation-placement hook run this
# GATING: any violation of any check - and any skipped class - fails. Scope is
# app/ only - factories and tests are convention territory for the review.
# The script ALWAYS ends with the summary line "AQ placement check: ..." - CI
# parses it as the liveness proof and asserts that check 1 inspected something -
# except on a self-test abort, which exits 2 with NO summary so CI fails too.
# Usage: sh scripts/check-command-validation-placement.sh   (from the repo root)
[ -d app ] || { echo "AQ check aborted - no app/ directory (run from the repo root)"; exit 2; }
# the three filters apply to `grep -rn` output (file:line:content)
comment_lines='^[^:]+:[0-9]+:[[:space:]]*(\*|/\*|//|#)'
http_boundary_path='^[^:]*/IO/Http/'
controller_path='^[^:]*/IO/Http/Controllers/'
controller_file='/IO/Http/Controllers/.*Controller\.php$'
command_name='\b[A-Z][A-Za-z0-9_]*Command\b'
data_class='^[[:space:]]*(final[[:space:]]+|abstract[[:space:]]+)?class[[:space:]]+[A-Za-z0-9_]+[[:space:]]+extends[[:space:]]+Data\b'
defines_rules='^[[:space:]]*public static function rules\('
validate_call='::validateAndCreate\('
sanctioned_call='(static|self)::validateAndCreate\(\$request->'
sanctioned_site="^[^:]*/UseCases/Commands/[^:]*:[0-9]+:.*${sanctioned_call}"
request_constructor='public static function from[A-Za-z0-9_]*\(Request '

# $1 = class names joined by `|`
from_call_of() { printf '%s' "\\b($1)::from\\("; }
# $1 = Class::method pairs joined by `|`
constructor_call_of() { printf '%s' "\\b($1)\\("; }
# stdin = PHP source lines; prints the Request-fed named constructors' names
constructor_names() {
  grep -oE -e "$request_constructor" | sed -E 's/^public static function (from[A-Za-z0-9_]*)\(Request $/\1/' | sort -u
}

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
expect_constructor_names() {
  # $1 a source line, $2 the names constructor_names must print for it
  extracted=$(printf '%s\n' "$1" | constructor_names)
  if [ "$extracted" != "$2" ]; then
    echo "self-test failed - constructor names of '$1' read '$extracted', expected '$2'"
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
self_test 'HTTP-boundary path' "$http_boundary_path" \
  'app/Companies/IO/Http/Controllers/CompaniesController.php:27:        $command = UpdateCompanyCommand::fromRequest($request);' \
  "app/Companies/UseCases/Interactors/X.php:9:        \$path = 'app/Companies/IO/Http/Controllers';"
self_test 'controller path' "$controller_path" \
  'app/Companies/IO/Http/Controllers/CompaniesController.php:27:        $filters = InvoiceFilters::from($query);' \
  'app/Companies/IO/Http/Resources/CompanyResource.php:27:        $filters = InvoiceFilters::from($query);'
self_test 'controller file' "$controller_file" \
  'app/Foundation/Auth/IO/Http/Controllers/TwoFactor/VerifyTwoFactorController.php' \
  'app/Foundation/Auth/IO/Http/Resources/SessionResource.php'
self_test 'command name' "$command_name" \
  '        $command = UpdateCompanyCommand::fromRequest($request);' \
  '        $response = $interactor->execute($command);'
self_test 'data class' "$data_class" \
  'class InvoiceFilters extends Data' \
  'class InvoiceCollection extends DataCollection'
self_test 'rules() definition' "$defines_rules" \
  '    public static function rules(): array' \
  '    public function rules(): array'
self_test '::validateAndCreate( call (named receiver)' "$validate_call" \
  '        $filters = InvoiceFilters::validateAndCreate($row);' \
  '        $filters = InvoiceFilters::from($row);'
self_test '::validateAndCreate( call (static receiver)' "$validate_call" \
  '        return static::validateAndCreate($data);' \
  '        return static::from($data);'
self_test 'sanctioned site (path-bound)' "$sanctioned_site" \
  'app/Billing/Invoices/UseCases/Commands/InvoiceFilters.php:70:        return static::validateAndCreate($request->query());' \
  'app/Billing/Invoices/UseCases/Interactors/ListInvoices.php:70:        return static::validateAndCreate($request->query());'
self_test 'sanctioned site (Request-fed)' "$sanctioned_site" \
  'app/Companies/UseCases/Commands/UpdateCompanyCommand.php:49:        return self::validateAndCreate($request->all());' \
  'app/Companies/UseCases/Commands/UpdateCompanyCommand.php:49:        return static::validateAndCreate($data);'
self_test 'sanctioned site (static or self only)' "$sanctioned_site" \
  'app/Companies/UseCases/Commands/UpdateCompanyCommand.php:49:        return static::validateAndCreate($request->all());' \
  'app/Companies/UseCases/Commands/UpdateCompanyCommand.php:49:        return InvoiceFilters::validateAndCreate($request->query());'
self_test '::from( call template (command)' "$(from_call_of 'FooCommand|BarFilters')" \
  '        $command = FooCommand::from($request->all());' \
  '        $plan = BillingPlan::from($request->input("plan"));'
self_test '::from( call template (filters)' "$(from_call_of 'FooCommand|BarFilters')" \
  '        $filters = BarFilters::from($request->query());' \
  '        $filters = BarFilters::fromQuery($request);'
self_test 'constructor call template' "$(constructor_call_of 'BarFilters::fromQuery|FooCommand::fromRequest')" \
  '        $filters = BarFilters::fromQuery($request);' \
  '        $filters = BarFilters::fromQueryString($request);'
expect_constructor_names '    public static function fromQuery(Request $request): static' 'fromQuery'
expect_constructor_names '    public static function fromArray(array $data): static' ''

fail=0
violations=0
checked=0
skipped=0
newline='
'
all_php=$(find app -name '*.php')
population_files=$(grep -rlE --include='*.php' -e "$data_class" app)
if [ -z "$population_files" ]; then
  echo "self-test failed - no class under app/ extends Data (checks 1 and 2 would scan nothing)"
  exit 2
fi
population=$(printf '%s\n' "$population_files" | sed -E 's#.*/##; s#\.php$##' | sort -u | tr '\n' '|' | sed 's/|$//')

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

# Sets $src (the app/ source files of class $1, space-joined) and $sources
# (how many). Runs under IFS=newline; a shell loop, because a process per class
# is slow on Windows.
sources_of() {
  src=''
  sources=0
  for candidate in $all_php; do
    case "$candidate" in
      */"$1".php) src="${src:+$src }$candidate"; sources=$((sources + 1)) ;;
    esac
  done
}

# Prints one `source|Class|controller` line per request DTO that resolves to
# exactly one source; skips the rest loudly. Runs under IFS=newline.
resolve_controller_request_dtos() {
  for mention in $(grep -HoE -e "$command_name" -e "\\b($population)\\b" $1 | sort -u); do
    controller=${mention%%:*}
    cls=${mention##*:}
    sources_of "$cls"
    case "$sources" in
      0) skip "no source file for $cls (referenced from $controller)" ;;
      1) checked=$((checked + 1)); resolved="${resolved:+$resolved$newline}$src|$cls|$controller" ;;
      *) skip "ambiguous source for $cls (referenced from $controller): $src" ;;
    esac
  done
}

check_controller_request_dtos_define_rules() {
  IFS=$newline
  controllers=$(printf '%s\n' "$all_php" | grep -E -e "$controller_file")
  resolved=''
  [ -z "$controllers" ] || resolve_controller_request_dtos "$controllers"
  if [ -n "$resolved" ]; then
    without_rules=$(grep -LE -e "$defines_rules" $(printf '%s\n' "$resolved" | cut -d'|' -f1 | sort -u))
    for entry in $resolved; do
      src=${entry%%|*}
      dto=${entry#*|}
      case "$newline$without_rules$newline" in
        *"$newline$src$newline"*) violation "HTTP-boundary command without rules(): ${dto%%|*} (referenced from ${dto#*|})" ;;
      esac
    done
  fi
  unset IFS
}

check_no_from_in_controllers() {
  sites=$(grep -rnE --include='*.php' -e "$(from_call_of "$population")" app | grep -E -e "$controller_path" | grep -vE -e "$comment_lines")
  [ -z "$sites" ] || violation_per_site "$sites" "::from( in a controller"
}

check_no_validate_outside_http_boundary() {
  sites=$(grep -rnE --include='*.php' -e "$validate_call" app | grep -vE -e "$http_boundary_path" | grep -vE -e "$comment_lines" | grep -vE -e "$sanctioned_site")
  [ -z "$sites" ] || violation_per_site "$sites" "validateAndCreate outside the HTTP boundary"
}

check_request_constructors_called_from_http_only() {
  pairs=''
  IFS=$newline
  for file in $(grep -rnE --include='*.php' -e "$validate_call" app | grep -E -e "$sanctioned_site" | cut -d: -f1 | sort -u); do
    cls=$(basename "$file" .php)
    methods=$(constructor_names < "$file")
    if [ -z "$methods" ]; then
      violation "Request-fed validateAndCreate outside a from...(Request ...) named constructor: $file"
      continue
    fi
    for method in $methods; do pairs="${pairs:+$pairs|}${cls}::${method}"; done
  done
  unset IFS
  [ -n "$pairs" ] || return 0
  sites=$(grep -rnE --include='*.php' -e "$(constructor_call_of "$pairs")" app | grep -vE -e "$http_boundary_path" | grep -vE -e "$comment_lines")
  [ -z "$sites" ] || violation_per_site "$sites" "Request-fed named constructor called outside the HTTP boundary"
}

check_controller_request_dtos_define_rules
check_no_from_in_controllers
check_no_validate_outside_http_boundary
check_request_constructors_called_from_http_only
echo "AQ placement check: $violations violation(s); $checked controller-referenced command(s) checked; $skipped skipped"
exit $fail
