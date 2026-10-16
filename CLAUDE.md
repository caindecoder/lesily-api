<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/telescope (TELESCOPE) - v5
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>

---

## HRMS Project Conventions (authoritative)

The Laravel Boost block above is auto-generated and covers Laravel/PHPUnit framework defaults. The **authoritative HRMS-specific conventions** live at:

`Documentation/05-operations/coding-conventions-plan.md` (in the HRMS workspace, parent of this `lesily-api/` directory)

**Key rules (locked session 054 — 2026-05-28):**

- **§2.2 Rules 1-11** — Test conventions: Sad → Edge → Happy ordering; grouped assertion helpers (2-5 aspects; max 3 params — every test helper, not only assertion helpers, s209); shared test data via `setUp()`; no HTTP status codes in test names (Rule 6 + grep enforcement); one scenario per test method (Rule 7); no transport identifiers in test names (Rule 8); exception-test idiom — `expectException` ONLY when the throw is the scenario's last observable, try/`fail()`/catch + post-throw state asserts otherwise (Rule 9, locked s138); validation rejections assert the **verbatim message** — `assertValidationRejected($response, $field, $message)`, messages copied from the RED output / `lang/en/validation.php`, never typed from memory (Rule 10, locked s187; resolves OQ-1-138); **expected HTTP statuses are stated by the test, never derived from production and never as a bare number** — a named Laravel assert (`assertUnprocessable()`, `assertForbidden()`, …) when the status is asserted directly, a `Response::HTTP_*` constant (`Illuminate\Http\Response`) when it travels as a value (`Tests\TestCase::assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::X)`, a DataProvider row); `assertStatus(` fed by `->status()` (the enum the code under test renders from — the expectation would move with the mutation) or by a bare integer, a parked `$x = …->status()` value, or the enum on the expected side of `assertSame`, is refused by the lefthook `status-assertions` hook (staged files, glob `tests/**/*.php` under the pinned `glob_matcher: doublestar` — `**/` matches zero directories too, so the root `tests/TestCase.php` is scanned) + the gating CI `quality` step over the whole tree (`scripts/check-status-assertions.sh` — comment lines `//`, `/*`, `*`, `#` excluded; every matcher and the comment filter self-tested at startup, a dead matcher aborts with no summary; summary-line liveness proof `Status-assertion check: N violation(s); tests: M file(s) scanned; app: K file(s) scanned`, CI asserts it exists and M > 0 and K > 0; Rule 11, locked s204; resolves OQ-1-177). **Production mirrors the rule (OQ-1-183, s205):** an emitted status is a `Response::HTTP_*` constant — `Illuminate\Http\Response` at the 45 migrated sites (the 38 `ApiErrorCode::status()` arms + 7 controller `setStatusCode(` sites; the pre-existing `RegisterController:34` takes the same constant from Symfony's `Response`, which Illuminate's extends); the same script's `app/` population refuses a bare three-digit `setStatusCode(` argument anywhere under `app/`, the two-argument `(201, $text)` form included (A1), and a bare arm in a status map, the last arm without its comma included (A2 — `ApiErrorCode.php` today; a second map is added to the script's `status_maps` list, a whole-tree run aborts if a listed map is missing, never a generic three-digit matcher over `app/`) via the lefthook `status-constants` hook (glob `app/**/*.php`) + the same gating CI step. The integer mutants on those lines cease because they are constants now — a mutant-count drop there is not lost coverage.
- **§2.8** — Style baselines: LeaveDates primary, subscription-platform parity.
- **§2.9** — `php artisan make:*` mandatory for ALL framework class scaffolding (pass `--no-interaction`). **CORRECTED s165:** `make:trait` (core), `make:aggregate` / `make:reactor` / `make:projector` / `make:storable-event` (spatie event-sourcing) and `make:data` (spatie data) all EXIST — verify with `php artisan list make` before claiming otherwise. Hand-creation is correct only for **Interactors** (no maker) and **value objects** (use `make:class`).
- **§2.1 Test strictness (s262)** — `phpunit.xml` sets `failOnRisky` + `failOnWarning`: a test that performs no assertion, or a run that raises a warning, fails the suite — the two attributes Infection already forces on every mutation run.
- **§2.10** — Abstract base TestCase per chunk module (`FoundationTestCase`, `EmployeeTestCase`, etc.) at `tests/Feature/{Module}/{Module}TestCase.php`.
- **§2.1 DB test-trait placement (OQ-1-181, s199)** — `use RefreshDatabase;` lives in `Tests\TestCase` ONLY (the LeaveDates shape); never re-declare a DB trait in a test class or module base — alone, combined (`use A, RefreshDatabase;`), opening a conflict-resolution block (`use RefreshDatabase {`) or as a leftover import (plain, aliased, or anywhere in a grouped `{A, B}` import) — it shadows the base class's `migrateDatabases()` override: Infection mutant processes (`INFECTION=1`) run an incremental `migrate` and reuse whatever schema their database already holds (un-tokenised runs: the initial run's `lesily_test`; tokenised CI runs: each token database, built once by its first worker); every other runner (ParaTest, phpunit, `artisan test`) migrates fresh per process. Gate: `scripts/check-db-trait-placement.sh` (lefthook `db-trait-placement` + the gating CI quality step; the pattern is self-tested at startup on every declaration form and all five trait names — a dead or loosened pattern aborts with no summary, OQ-1-185). Contract: `tests/Feature/Foundation/InfectionWorkersReuseTheMigratedSchemaTest.php`. The full mutation run (`mutation.yml`) is `--threads="$(nproc)"` — the runner's real core count (4 public / 2 private); NOT `--threads=max`, which Infection resolves to cores − 1 (one worker on 2 vCPU — the s199 P3 read).
- **§2.11** — Test routes centralized in `routes/testing.php` (env-gated); NO inline `Route::middleware(...)->post(...)` inside test method bodies.
- **§2.12** — DataProviders mandatory for ≥3-variation parametric scenarios.
- **§2.13** — Factory state methods mandatory (`Role::factory()->admin()`, `Company::factory()->onPlan(Pro)`); no inline `factory()->create(['field' => 'value'])`.
- **§2.14 (locked s132, extended s142)** — Test-class unit & naming: one behavior-named test class per behavior/seam (no `*AggregateTest`/`*ProjectionTest`/`*ModelTest`; a behavior's event + projection assertions share ONE walk); verb-phrase names count as behavior names; carve-outs (console-command/seeder/mail-render/infra-primitive/adapter) need an `@testclass-carveout:` docblock; a behavior's happy + sad scenarios share ONE class (Sad→Edge→Happy) — sanctioned split triggers are a second responsibility (SRP), a different seam, a different actor role, or a different domain precondition, never a test partition — and test-partition class names (`*SadPath*`/`*HappyPath*`/`*EdgeCase*`/`*ErrorPath*`/`*Negative*`) are banned outright with **no** carve-out escape — enforced by the lefthook `test-class-naming` hook (staged files) + the gating CI `quality` step over the whole `tests/` tree (`scripts/check-test-class-naming.sh`: the six artifact suffixes plus `*SeederTest`/`*MailRenderTest`/`*CommandTest` need the tag; summary-line liveness proof — OQ-1-170, s203; all 14 suffixes and the carve-out regex are self-tested at startup, both directions — OQ-1-185).
- **§2.15 (locked s132, extended s184)** — ES-worthiness gate + write-path chain: every increment declares `ES-write | plain-write | read (± audited) | n/a (<reason>)` at `/tdd-red` Phase 2 (domain facts in doubt → ES; plain must be argued; the `read (audited)` sub-form covers a read of personal data that appends one `audit_trail` row — a B3 admin access-log read, or a data subject's read of their own export (N58, s249); `n/a` = the increment adds or changes no write path and no read path — config, test-only, a delivery-layer guard, a refactor-under-green); canonical chain `controller → command → interactor → aggregate → projector → reactor` with layer-skip bans (controllers never `::retrieve(`/`DB::`/`->persist(`; `DB::transaction` only in interactors, and an interactor that calls `persist()` does so inside its own `DB::transaction` (OQ-1-188 — spatie's `persist()` opens none, so unwrapped a projector failure leaves the event committed and the read model stale); projectors sole writers of the projector-owned **columns** of projected tables — a table may be column-disjointly dual-written by design, e.g. `users`; reactors never cross-aggregate). The greppable halves of bans 1, 2 and 4 are mechanical: the lefthook `write-path-chain` hook (staged `app/` files) + the gating CI `quality` step over the whole tree (`scripts/check-write-path-chain.sh` — controller `::retrieve(`/`DB::`/`->persist(`, `DB::transaction` outside interactors, an interactor file that persists (`->persist(` / `::persistInTransaction(`) with no `DB::transaction(` (C4, file-level), and in a reactor the named Eloquent / query-builder / relation write verbs (deliberately stricter than the cross-aggregate clause: "side effects only" made mechanical); call-form only, leading comment lines excluded; every matcher self-tested, per-layer populations asserted on CI — OQ-1-170, s203). Ban 3 (projector column ownership), ban 1's repository-write / token-lifecycle half and ban 2's read-model half stay declaration + review.
- **§2.15.B.2 command construction (amendment AQ, s187)** — `::validateAndCreate` only at the HTTP boundary (controllers / FormRequests, or a `Request`-fed named constructor on the command or filters DTO — `fromRequest` / `fromQuery` — whose only callers are controllers); every internal construction via `::from()`; internal-only commands carry no `rules()`; server-injected keys (actor ids, route ids) carry no rule. Gate: `scripts/check-command-validation-placement.sh` on the lefthook `command-validation-placement` hook (whole `app/` tree whenever an `app/` PHP file is staged) + the gating CI `quality` step (gating since OQ-1-172 T7; the legacy bound walked 8 at T3 → 6 after T4 → 5 after T5 → 0 after T6 — s193 PR #146: 0 violations / 24 commands checked). Four checks since OQ-1-185: every request DTO a controller references — the `*Command` names plus every class that `extends Data`, filters DTOs included — defines `rules()` (27 checked); no `<DataClass>::from(` in a controller (an enum's `::from(` is outside the population); no `::validateAndCreate(` on ANY receiver outside `IO/Http/` except the sanctioned line shape (`static::` / `self::` fed by `$request->`, in a `UseCases/Commands/` file); those constructors are called from `IO/Http/` only. Comment lines (`*`, `/*`, `//`, `#`) excluded; every matcher self-tested at startup, a dead one aborts with no summary. Local mutation runs go through `composer mutate:local` (= `PAO_DISABLE=1 php -d auto_prepend_file= vendor/infection/infection/bin/infection --threads=1` with Composer's process timeout disabled, amendment AP; the `-d` clears Herd's `auto_prepend_file`, which Infection >= 0.35 refuses to run under - OQ-1-187 L10) followed by `php scripts/check-mutation-kills.php infection.json`; `infection.json5` ignore entries are individual, each with a rationale and a `remove when` clause where the equivalence can expire (§2.7 ledger contract). A cast that converts nothing and only satisfies Larastan is deleted and its type stated (`@var`, a typed local or a typed closure), never ledgered (OQ-1-172 T7 ruling 8, written into §2.7 s267). Infection never reports an entry that matches nothing, so `tests/Unit/Conventions/InfectionIgnoreEntriesPointAtLiveCodeTest.php` (enforcer `conventions/Infection/DeadIgnoreEntries.php`) fails any entry whose class or method is gone or whose `::<line>` key starts no construct of its mutator's kind — a moved line fails the PR, not the next weekly run.
- **§2.16 String columns (locked s143)** — every variable-length string column is `->string()` (varchar(255)); deviate only for a documented semantic constraint (`ip_address` varchar(45), `remember_token` varchar(100)), never for index length.
- **§2.17 Request-DTO naming (rewritten s259)** — every request-DTO property is camelCase (SonarCloud `php:S116` is fixed, never accepted); body keys are camelCase on the wire too, so a command carries no `#[MapInputName]`; a documented snake query key (`?per_page=`) reaches its camelCase property through `#[MapInputName('per_page')]` only on a `*Filters` class whose `QUERY_KEY_WHITELIST` names that key — `rules()` keys, whitelists and messages keep the wire spelling.
- **§2.18 Comment policy (locked s167, amendment AJ)** — no journal, evidentiary or review-transcript comments in production or tests: no defect-history narration, no "this test proves…" docblocks, no correction trails. Allowed: PHPDoc type / `@throws` annotations, the §2.2 `// Given / When / Then` comments (a citation payload inside them is not laundered by the G/W/T head), and constraint comments stating only what the code cannot show. History lives in `git log`, session logs and PR threads.
- **§2.19 N+1 prevention (locked s197, OQ-1-178)** — `Model::shouldBeStrict(! $this->app->isProduction())` in `AppServiceProvider::boot` is the net (guards off in production; no production lazy-load signal until OQ-1-180); an N+1 is fixed at the collection level in the owning use case (`with()`), never by a per-item `load()` inside an iteration (`/tdd-red` BB grep, `/clean-code-review` Dimension 14); no query-count sweep — a relative-bound row only for a model-collection endpoint whose resource reads a relation; factories mirror their table's columns, nullable ones as `null` (`UserFactory` precedent). Config contract: `tests/Feature/Foundation/EloquentStrictModeRejectsUnsafeModelAccessTest.php`.
- **§2.20 Audit codes (locked s260)** — every `audit_trail` `action` / `subject_type` is a case of `App\Foundation\AuditTrail\Enums\AuditAction` / `AuditSubjectType`; `AuditEntry` casts both, so a code that is not a case is refused at write. Every case has words in `lang/en/dsar.php` (`codes.action.*` / `codes.subject_type.*`); the all-cases DataProvider in `ExportDsarReportTest` is the gate. A new audit row adds its case first.
- **§2.21 Model key reads (locked s265, `PROC-model-key-getkey`)** — a model's key is read with `getKey()`, never `->id` — on a model, a nullsafe `?->`, a typed `$this->resource`, and a `JsonResource`'s own `$this->id` (the proxy forwards to its model). A query-builder row (`stdClass`) or a plain object keeps its `->id`; a resource over a row spells `$this->resource->id`. The rule fires only on a receiver PHPStan knows is a model or resource — an untyped (`mixed`) receiver passes, so a resource declares its `@mixin` / `@property-read`. Gate: `conventions/PHPStan/ModelKeyReadRule.php` (identifier `lesily.modelKeyRead`) in the Larastan run — CI `quality` and local `vendor/bin/phpstan analyse`, not lefthook; tested by `tests/Unit/Conventions/ModelKeyReadsUseGetKeyTest.php` over fixtures excluded from the main analysis. `conventions/README.md` maps each convention to its enforcer.
- **§4.4 Codecov upload (s262)** — its own `codecov` job (`needs: test`, the shared `coverage-reports` artifact) with `fail_ci_if_error: true`: a failed upload fails that job alone, never silently drops `codecov/patch`, and never skips `sonarcloud`. Dependabot runs read `CODECOV_TOKEN` from the repo's Dependabot secrets.
- **§4.11 Runner image (s266, `PROC-ci-runner-ubuntu-2604-trial`)** — every job in `ci.yml` and `mutation.yml` pins one named image (`runs-on: ubuntu-26.04`), never `ubuntu-latest`; a new job copies it. An image change is its own PR, proved by its CI and a dispatched full mutation run (`gh workflow run mutation.yml --ref <branch>`, clear of the Sat cron — both share the `mutation-main` concurrency group). The image ships the libraries Chromium loads (the real-PDF tests prove them) and, on 26.04, a RAM-backed `/tmp` that `mutation.yml` measures. Review-enforced, no gate.
- **§4.9 Dependency audit gate (questionnaire §15b; OQ-1-186, s217)** — the CI `setup` job runs `composer audit --locked --abandoned=report --ignore-severity=low --ignore-severity=medium` and `npm audit --audit-level=high` on every build, **before anything is installed**: every other job needs `setup`, so a flagged lock is refused before a dependency's install scripts can execute in any job (`setup-php`'s own recommendation; it departs from §15b's "in the `quality` job" wording on purpose). **Composer:** a high, critical or **unrated** advisory in `composer.lock` fails the step, `require-dev` packages included; low / medium advisories and abandoned packages print and never gate. **npm:** a high or critical advisory in `package-lock.json` fails the step, the dev toolchain included; moderate and below print. The exit status is the whole verdict — Composer's `summary` format counts ignored advisories too, so no output line is parsed; both tools fail closed when the advisory source refuses the connection or answers with an error, and Composer refuses an empty lock (npm does not — an emptied `package-lock.json` exits 0 here and is refused by the `test` job's `npm ci` instead: `EUSAGE`, out of sync with `package.json`). CI only, no hook (a network call breaks the 1–2 s hook budget). **No bypass** — `|| true`, `continue-on-error` and `--ignore-unreachable` are Rule #13 violations. A red gate is fixed by updating the package: a targeted `composer update <pkg> --with-all-dependencies`; for npm, keep the lock diff to the fixed blocks (at s217 `npm audit fix` also pruned the optional-peer `@emnapi/core` / `@emnapi/runtime` blocks that three wasm packages in the lock still reference — the entries behind the s135 `npm ci` EUSAGE rounds — so that churn was refused and only the two fixed blocks were transplanted onto `main`'s lock). A high advisory with no fix is ONE ruled ignore entry with a reason and a remove-when clause, approved by Chamil. `composer.json` carries no `*` constraint. **`npm audit signatures` (OQ-1-187, s218)** runs in the `test` job right after `npm ci` — registry signatures and provenance attestations of every installed package verified against the registry's published keys (`npm/cli` `lib/utils/verify-signatures.js`); it fails closed on an invalid or missing signature, an unknown key, a registry publishing no keys, or no installed dependencies, so it cannot pass vacuously; it needs `node_modules`, which is why it is not a `setup` step. **Dependabot `cooldown: default-days: 7` (s267, `PROC-15b-residue`)** on composer, npm and github-actions in `.github/dependabot.yml`: Dependabot proposes only versions out 7 days — §15b's one-week cooling, automatic for direct versions; security updates are exempt by GitHub's design (this gate forces those), and transitive lock entries a PR pulls in stay a review-time check (`npm install --before`). The quarterly dependency review is logged in the workspace's `Documentation/05-operations/2026-10-16-dependency-review-log.md` (a hand "still used?" table — `depcheck` is archived). Sibling truths from the same increment: `composer.json` says `php ^8.4` (Dependabot's composer updater resolves on the constraint's floor and had failed every run on `^8.3`); Semgrep runs on the native `semgrep/semgrep` image with `--error`; OpenAPI generation is guarded on a path annotation by `scripts/generate-openapi-if-annotated.sh` (none exists yet — Decision 17 / #86).
- **§8 Rule #13** — Fix root cause, not CI symptom. Forbidden: reflection mutation-killer tests, path-driven mega-tests, hard-coded enum-arm pinning, unjustified suppressions, retry/skip to silence flakiness.
- **§8 Rule #14** — Laravel-docs-first: cite `laravel.com/docs/13.x/<topic>` URL in PR description when introducing any new Laravel concern.
- **§8 Rule #15** — Reference-codebase consultation mandatory: every UC's TDD-RED MUST cite at least one LeaveDates (primary) or subscription-platform (secondary) analog file:line in PR description, OR explicitly document greenfield-UC designation.

**Reference-codebase precedence:** LeaveDates primary, subscription-platform secondary. When they conflict, LeaveDates wins by default. Use `/reference-scan {uc-id} {concern-keywords}` skill to scope-grep both for analog patterns before TDD-RED. See parent's `.claude/memory/feedback_leavedates-primary.md` for the precedence rule + 3 locked divergences from LeaveDates.

**Playbook:** SUPERSEDED — graduated to the chunk-agnostic `/tdd-red` skill in s056 (`.claude/skills/tdd-red/SKILL.md`, HRMS parent repo; amendments now tracked there). The old `Documentation/06-design/chunk-1/_process/tdd-red-playbook.md` is a frozen historical record (s143 pointer fix).

