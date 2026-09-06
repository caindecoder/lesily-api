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

- **§2.2 Rules 1-11** — Test conventions: Sad → Edge → Happy ordering; grouped assertion helpers (2-5 aspects, max 3 params); shared test data via `setUp()`; no HTTP status codes in test names (Rule 6 + grep enforcement); one scenario per test method (Rule 7); no transport identifiers in test names (Rule 8); exception-test idiom — `expectException` ONLY when the throw is the scenario's last observable, try/`fail()`/catch + post-throw state asserts otherwise (Rule 9, locked s138); validation rejections assert the **verbatim message** — `assertValidationRejected($response, $field, $message)`, messages copied from the RED output / `lang/en/validation.php`, never typed from memory (Rule 10, locked s187; resolves OQ-1-138); **expected HTTP statuses are stated by the test, never derived from production and never as a bare number** — a named Laravel assert (`assertUnprocessable()`, `assertForbidden()`, …) when the status is asserted directly, a `Response::HTTP_*` constant (`Illuminate\Http\Response`) when it travels as a value (`Tests\TestCase::assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::X)`, a DataProvider row); `assertStatus(` fed by `->status()` (the enum the code under test renders from — the expectation would move with the mutation) or by a bare integer is refused by the lefthook `status-assertions` hook (staged `tests/**/*.php`) + the gating CI `quality` step over the whole tree (`scripts/check-status-assertions.sh` — every matcher and the comment filter self-tested at startup; Rule 11, locked s204; resolves OQ-1-177; the production side — `Response::HTTP_*` in `ApiErrorCode::status()` and the controllers — is OQ-1-183).
- **§2.8** — Style baselines: LeaveDates primary, subscription-platform parity.
- **§2.9** — `php artisan make:*` mandatory for ALL framework class scaffolding (pass `--no-interaction`). **CORRECTED s165:** `make:trait` (core), `make:aggregate` / `make:reactor` / `make:projector` / `make:storable-event` (spatie event-sourcing) and `make:data` (spatie data) all EXIST — verify with `php artisan list make` before claiming otherwise. Hand-creation is correct only for **Interactors** (no maker) and **value objects** (use `make:class`).
- **§2.10** — Abstract base TestCase per chunk module (`FoundationTestCase`, `EmployeeTestCase`, etc.) at `tests/Feature/{Module}/{Module}TestCase.php`.
- **§2.1 DB test-trait placement (OQ-1-181, s199)** — `use RefreshDatabase;` lives in `Tests\TestCase` ONLY (the LeaveDates shape); never re-declare a DB trait in a test class or module base — alone, combined (`use A, RefreshDatabase;`) or as a leftover import — it shadows the base class's `migrateDatabases()` override: Infection mutant processes (`INFECTION=1`) run an incremental `migrate` and reuse whatever schema their database already holds (un-tokenised runs: the initial run's `lesily_test`; tokenised CI runs: each token database, built once by its first worker); every other runner (ParaTest, phpunit, `artisan test`) migrates fresh per process. Gate: `scripts/check-db-trait-placement.sh` (lefthook `db-trait-placement` + the gating CI quality step). Contract: `tests/Feature/Foundation/InfectionWorkersReuseTheMigratedSchemaTest.php`. The full mutation run (`mutation.yml`) is `--threads="$(nproc)"` — the runner's real core count (4 public / 2 private); NOT `--threads=max`, which Infection resolves to cores − 1 (one worker on 2 vCPU — the s199 P3 read).
- **§2.11** — Test routes centralized in `routes/testing.php` (env-gated); NO inline `Route::middleware(...)->post(...)` inside test method bodies.
- **§2.12** — DataProviders mandatory for ≥3-variation parametric scenarios.
- **§2.13** — Factory state methods mandatory (`Role::factory()->admin()`, `Company::factory()->onPlan(Pro)`); no inline `factory()->create(['field' => 'value'])`.
- **§2.14 (locked s132, extended s142)** — Test-class unit & naming: one behavior-named test class per behavior/seam (no `*AggregateTest`/`*ProjectionTest`/`*ModelTest`; a behavior's event + projection assertions share ONE walk); verb-phrase names count as behavior names; carve-outs (console-command/seeder/mail-render/infra-primitive/adapter) need an `@testclass-carveout:` docblock; a behavior's happy + sad scenarios share ONE class (Sad→Edge→Happy) — sanctioned split triggers are a second responsibility (SRP), a different seam, a different actor role, or a different domain precondition, never a test partition — and test-partition class names (`*SadPath*`/`*HappyPath*`/`*EdgeCase*`/`*ErrorPath*`/`*Negative*`) are banned outright with **no** carve-out escape — enforced by the lefthook `test-class-naming` hook (staged files) + the gating CI `quality` step over the whole `tests/` tree (`scripts/check-test-class-naming.sh`: the six artifact suffixes plus `*SeederTest`/`*MailRenderTest`/`*CommandTest` need the tag; summary-line liveness proof — OQ-1-170, s203).
- **§2.15 (locked s132, extended s184)** — ES-worthiness gate + write-path chain: every increment declares `ES-write | plain-write | read (± audited)` at `/tdd-red` Phase 2 (domain facts in doubt → ES; plain must be argued; the `read (audited)` sub-form covers a B3 admin access-log read that appends one `audit_trail` row); canonical chain `controller → command → interactor → aggregate → projector → reactor` with layer-skip bans (controllers never `::retrieve(`/`DB::`/`->persist(`; `DB::transaction` only in interactors; projectors sole writers of the projector-owned **columns** of projected tables — a table may be column-disjointly dual-written by design, e.g. `users`; reactors never cross-aggregate). The greppable halves of bans 1, 2 and 4 are mechanical: the lefthook `write-path-chain` hook (staged `app/` files) + the gating CI `quality` step over the whole tree (`scripts/check-write-path-chain.sh` — controller `::retrieve(`/`DB::`/`->persist(`, `DB::transaction` outside interactors, and in a reactor the named Eloquent / query-builder / relation write verbs (deliberately stricter than the cross-aggregate clause: "side effects only" made mechanical); call-form only, leading comment lines excluded; every matcher self-tested, per-layer populations asserted on CI — OQ-1-170, s203). Ban 3 (projector column ownership), ban 1's repository-write / token-lifecycle half and ban 2's read-model half stay declaration + review.
- **§2.15.B.2 command construction (amendment AQ, s187)** — `::validateAndCreate` only at the HTTP boundary (controllers / FormRequests); every internal construction via `::from()`; internal-only commands carry no `rules()`; server-injected keys (actor ids, route ids) carry no rule. CI mirror: `scripts/check-command-validation-placement.sh` in the `quality` job (gating since OQ-1-172 T7; the legacy bound walked 8 at T3 → 6 after T4 → 5 after T5 → 0 after T6 — s193 PR #146: 0 violations / 24 commands checked). Local mutation runs go through `composer mutate:local` (= `PAO_DISABLE=1 vendor/bin/infection --threads=1` with Composer's process timeout disabled, amendment AP) followed by `php scripts/check-mutation-kills.php infection.json`; `infection.json5` ignore entries are individual, each with a rationale and a `remove when` clause where the equivalence can expire (§2.7 ledger contract).
- **§2.19 N+1 prevention (locked s197, OQ-1-178)** — `Model::shouldBeStrict(! $this->app->isProduction())` in `AppServiceProvider::boot` is the net (guards off in production; no production lazy-load signal until OQ-1-180); an N+1 is fixed at the collection level in the owning use case (`with()`), never by a per-item `load()` inside an iteration (`/tdd-red` BB grep, `/clean-code-review` Dimension 14); no query-count sweep — a relative-bound row only for a model-collection endpoint whose resource reads a relation; factories mirror their table's columns, nullable ones as `null` (`UserFactory` precedent). Config contract: `tests/Feature/Foundation/EloquentStrictModeRejectsUnsafeModelAccessTest.php`.
- **§8 Rule #13** — Fix root cause, not CI symptom. Forbidden: reflection mutation-killer tests, path-driven mega-tests, hard-coded enum-arm pinning, unjustified suppressions, retry/skip to silence flakiness.
- **§8 Rule #14** — Laravel-docs-first: cite `laravel.com/docs/13.x/<topic>` URL in PR description when introducing any new Laravel concern.
- **§8 Rule #15** — Reference-codebase consultation mandatory: every UC's TDD-RED MUST cite at least one LeaveDates (primary) or subscription-platform (secondary) analog file:line in PR description, OR explicitly document greenfield-UC designation.

**Reference-codebase precedence:** LeaveDates primary, subscription-platform secondary. When they conflict, LeaveDates wins by default. Use `/reference-scan {uc-id} {concern-keywords}` skill to scope-grep both for analog patterns before TDD-RED. See parent's `.claude/memory/feedback_leavedates-primary.md` for the precedence rule + 3 locked divergences from LeaveDates.

**Playbook:** SUPERSEDED — graduated to the chunk-agnostic `/tdd-red` skill in s056 (`.claude/skills/tdd-red/SKILL.md`, HRMS parent repo; amendments now tracked there). The old `Documentation/06-design/chunk-1/_process/tdd-red-playbook.md` is a frozen historical record (s143 pointer fix).

