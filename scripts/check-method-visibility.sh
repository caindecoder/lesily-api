#!/bin/sh
# Sec 2.23 (coding-conventions-plan; PROC-private-to-protected, s276): a method is
# protected, never private - the LeaveDates shape (app/ 2,815 protected : 70 private,
# Tests/ 5,117 : 101). The one exemption is `private function __construct` behind a
# public static named constructor (a value object, an exception); whether the named
# constructor exists is review (clean-code-review Dimension 13). Properties, promoted
# constructor parameters, constants and readonly carry no `function` token and are
# outside the rule (AL8); in a projector / reactor / aggregate a helper never takes an
# event or command as its only parameter (spatie dispatches it) - review, not here.
#  M1 a method declared private, in any modifier order (private static, static
#     private, final private, abstract private); keywords case-insensitive, as PHP's are
# Comment lines are excluded (//, /*, * and # openers - the check-write-path-chain.sh
# filter); the __construct( exemption is a negative filter on the hits. Every matcher,
# the comment filter, the exemption and the path classifier are self-tested at startup
# on a planted hit and a planted miss; a matcher that cannot match aborts with exit 2
# and NO summary line.
# Shared by lefthook pre-commit (method-visibility: staged **/*.php - the script routes
# each file to its root or skips it) and the CI quality job (whole tree: app, tests,
# database/factories, database/seeders, scripts, conventions - the Larastan roots; a
# missing root aborts with exit 2 and no summary). ALWAYS ends with the summary line
# CI parses; each root counts the files actually inspected.
# Usage: sh scripts/check-method-visibility.sh [file...]   (no args = scan the six roots)
# anchored at the line start or after `{` / `;` (a one-line class body is a declaration
# too); a trailing `// private function` comment sits after `/`, so it never matches
private_method='(^|[{;])[[:space:]]*((final|static|abstract)[[:space:]]+)*private[[:space:]]+((static|final|abstract)[[:space:]]+)*function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*[[:space:]]*\('
# applied to the hits as a negative filter: the one exempt method name
constructor='function[[:space:]]+__construct[[:space:]]*\('
# applied to `grep -n` output (line:content), so the anchor sits after the number
comment='^[0-9]+:[[:space:]]*(\*|/\*|//|#)'
# the whole-tree roots (phpstan.neon paths)
roots='app tests database/factories database/seeders scripts conventions'

self_test() {
  # $1 label, $2 pattern, $3 a line the pattern MUST match, $4 a line it must NOT match
  if ! printf '%s\n' "$3" | grep -qiE "$2"; then
    echo "self-test failed - $1 cannot match its positive control"
    exit 2
  fi
  if printf '%s\n' "$4" | grep -qiE "$2"; then
    echo "self-test failed - $1 matches its negative control"
    exit 2
  fi
}
self_test 'M1 private method' "$private_method" \
  '    private function foo(): void' \
  '    protected function foo(): void'
self_test 'M1 private method (static)' "$private_method" \
  '    private static function foo(): string' \
  '    public static function foo(): string'
self_test 'M1 private method (static first)' "$private_method" \
  '    static private function foo(): void' \
  '    static protected function foo(): void'
self_test 'M1 private method (final)' "$private_method" \
  '    final private function foo(): void' \
  '    final protected function foo(): void'
self_test 'M1 private method (abstract, in a trait)' "$private_method" \
  '    abstract private function foo(): void;' \
  '    abstract protected function foo(): void;'
self_test 'M1 private method (keyword case)' "$private_method" \
  '    PRIVATE function foo(): void' \
  '    PUBLIC function foo(): void'
self_test 'M1 private method (tab indent)' "$private_method" \
  "$(printf '\tprivate function foo(): void')" \
  "$(printf '\tprivate string $foo;')"
self_test 'M1 not a property' "$private_method" \
  '    private function foo(): void' \
  '    private string $x;'
self_test 'M1 not a readonly property' "$private_method" \
  '    private function foo(): void' \
  '    private readonly Foo $y;'
self_test 'M1 not a constant' "$private_method" \
  '    private function foo(): void' \
  '    private const X = 1;'
self_test 'M1 not a promoted parameter' "$private_method" \
  '    private function foo(): void' \
  '        private Foo $z,'
self_test 'M1 anchored at the declaration' "$private_method" \
  '    private function foo(): void' \
  '        $x = private_function(1);'
self_test 'M1 one-line class body' "$private_method" \
  'class ZzPlanted { private function foo(): void {} }' \
  'class ZzPlanted { protected function foo(): void {} }'
self_test 'M1 not a trailing comment' "$private_method" \
  '    $this->foo(); private function bar(): void {}' \
  '    $x = 1; // private function foo()'
self_test 'M3 constructor exemption' "$constructor" \
  '    private function __construct(string $message)' \
  '    private function __constructor(): void'
self_test 'M3 constructor exemption (name boundary)' "$constructor" \
  '    private function __construct(' \
  '    private function construct(): void'
self_test 'M3 constructor exemption (promoted form)' "$constructor" \
  '    private function __construct(public readonly ApiErrorCode $errorCode, string $message)' \
  '    private function __construct_legacy(): void'
self_test 'comment filter (docblock)' "$comment" \
  '12:     * private function foo()' \
  '12:    private function foo(): void'
self_test 'comment filter (// form)' "$comment" \
  '12:    // private function foo()' \
  '12:    private static function foo(): string'
self_test 'comment filter (/* form)' "$comment" \
  '12:    /* private function foo() */' \
  '12:    static private function foo(): void'
self_test 'comment filter (# form)' "$comment" \
  '12:    # private function foo()' \
  '12:    final private function foo(): void'

classify() {
  # $1 path -> its root label, or `skip`
  case "$1" in
    *.php) ;;
    *) echo skip; return 0 ;;
  esac
  case "$1" in
    app/*|./app/*|*/app/*) echo app ;;
    tests/*|./tests/*|*/tests/*) echo tests ;;
    database/factories/*|./database/factories/*|*/database/factories/*) echo database ;;
    database/seeders/*|./database/seeders/*|*/database/seeders/*) echo database ;;
    scripts/*|./scripts/*|*/scripts/*) echo scripts ;;
    conventions/*|./conventions/*|*/conventions/*) echo conventions ;;
    *) echo skip ;;
  esac
}
expect_root() {
  # $1 path, $2 the root label it must classify as
  got=$(classify "$1")
  [ "$got" = "$2" ] || { echo "self-test failed - path classifier: $1 read as '$got', expected '$2'"; exit 2; }
}
expect_root 'app/Foundation/Http/Middleware/X.php' app
expect_root './tests/TestCase.php' tests
expect_root 'tests/Feature/Foundation/X.php' tests
expect_root 'database/factories/UserFactory.php' database
expect_root 'database/seeders/RolesPermissionsSeeder.php' database
expect_root 'database/migrations/0001_01_01_000000_create_users_table.php' skip
expect_root 'scripts/crap-radar.php' scripts
expect_root 'conventions/PHPStan/ModelKeyReadRule.php' conventions
expect_root 'routes/testing.php' skip
expect_root 'bootstrap/app.php' skip
expect_root 'config/app.php' skip
expect_root 'app/README.md' skip

violations=0
scanned_app=0
scanned_tests=0
scanned_database=0
scanned_scripts=0
scanned_conventions=0

report() {
  # $1 file
  hits=$(grep -niE "$private_method" "$1" | grep -vE "$comment" | grep -viE "$constructor")
  [ -n "$hits" ] || return 0
  n=$(printf '%s\n' "$hits" | wc -l | tr -d ' ')
  printf '%s\n' "$hits" | sed "s|^|sec-2.23 violation - a private method (make it protected; only __construct behind a named constructor may be private): $1:|"
  violations=$((violations + n))
}

inspect() {
  f=$1
  [ -f "$f" ] || return 0
  case "$(classify "$f")" in
    app) scanned_app=$((scanned_app + 1)) ;;
    tests) scanned_tests=$((scanned_tests + 1)) ;;
    database) scanned_database=$((scanned_database + 1)) ;;
    scripts) scanned_scripts=$((scanned_scripts + 1)) ;;
    conventions) scanned_conventions=$((scanned_conventions + 1)) ;;
    *) return 0 ;;
  esac
  report "$f"
}

if [ "$#" -gt 0 ]; then
  for f in "$@"; do inspect "$f"; done
else
  # whole-tree run: every root must exist, or its population reads 0 while the
  # summary still prints - abort with no summary, like a dead matcher
  for r in $roots; do
    if [ ! -d "$r" ]; then
      echo "self-test failed - root $r not found (its population would read 0)"
      exit 2
    fi
  done
  # the roots expand under the default IFS; only the file loop splits on newlines
  files=$(find $roots -name '*.php')
  if [ -z "$files" ]; then
    echo "self-test failed - the whole-tree walk found no PHP file (every population would read 0)"
    exit 2
  fi
  OLDIFS=$IFS
  IFS='
'
  for f in $files; do inspect "$f"; done
  IFS=$OLDIFS
fi
scanned=$((scanned_app + scanned_tests + scanned_database + scanned_scripts + scanned_conventions))
echo "Method-visibility check: ${violations} violation(s); ${scanned} file(s) scanned (app ${scanned_app}, tests ${scanned_tests}, database ${scanned_database}, scripts ${scanned_scripts}, conventions ${scanned_conventions})"
[ "$violations" -eq 0 ]
