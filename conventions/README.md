# Conventions

The code that enforces Lesily's coding conventions. The rules themselves are written once, in the workspace's `Documentation/05-operations/coding-conventions-plan.md`; this folder holds only their enforcers. Each enforcer proves itself: a PHPStan rule by its `RuleTestCase`, a checker class by its unit test, a gate script by the self-test it runs at startup.

| Rule (coding-conventions §) | Enforcer | Runs on |
|---|---|---|
| §2.21 — a model key is read with `getKey()`, not `->id` | `PHPStan/ModelKeyReadRule.php` (identifier `lesily.modelKeyRead`; test `tests/Unit/Conventions/ModelKeyReadsUseGetKeyTest.php`) | `vendor/bin/phpstan analyse` — CI `quality`, local |
| §2.7 — every `infection.json5` ignore entry names live code (class, method, and a `::<line>` key on a construct of its mutator's kind) | `Infection/DeadIgnoreEntries.php` (test `tests/Unit/Conventions/InfectionIgnoreEntriesPointAtLiveCodeTest.php`) | the suite — CI `test`, local |
| §2.22 — every route at or under `api/`, on any domain, carries `RecordAccessOutcome`, the access-log hook | `tests/Feature/Foundation/AccessLog/RecordAccessOutcomeOnEveryApiRouteTest.php` (walks the router, groups expanded; named exemptions must exist and lack the hook) | the suite — CI `test`, local |
| §2.22 — every `api/v1` route behind `auth.company` carries `consent`, the re-consent gate, unless a named exemption | `tests/Feature/Foundation/RequireCurrentConsentOnEveryCompanyRouteTest.php` (walks the router; the exemptions must exist behind `auth.company` and lack the gate) | the suite — CI `test`, local |
| §2.24 — a command's ids, closed sets, dates and true/false flags are typed, and a mapper on a command only drops an id's `Id` suffix (K5a) | `tests/Feature/Foundation/CommandPropertiesAreTypedTest.php` (walks every `Data` class under `app/**/UseCases/Commands/` and the item class of each nested Data list a command holds; no property may be untyped — a deliberate string carries `#[OpaqueString('<why>')]`; the sweep's named list was emptied and deleted at inc-6, s308) | the suite — CI `test`, local |
| §2.25 — every file in a commands folder is a request object named `…Command`; shared fields come from a parent, never a trait | `tests/Feature/Foundation/CommandFoldersHoldOnlyCommandsTest.php` (walks every file under `app/**/UseCases/Commands/`) | the suite — CI `test`, local |
| §2.23 — a method is `protected`, never `private` (only `__construct` behind a named constructor) | `scripts/check-method-visibility.sh` *(moving here)* — the six Larastan roots | lefthook `method-visibility`, CI `quality` |
| §2.1 — the database test trait lives in `tests/TestCase.php` only | `scripts/check-db-trait-placement.sh` *(moving here — card `PROC-conventions-folder`)* | lefthook `db-trait-placement`, CI `quality` |
| §2.2 Rule 11 — HTTP statuses spelled by name, tests and `app/` | `scripts/check-status-assertions.sh` *(moving here)* | lefthook `status-assertions` + `status-constants`, CI `quality` |
| §2.14 — behaviour-named test classes | `scripts/check-test-class-naming.sh` *(moving here)* | lefthook `test-class-naming`, CI `quality` |
| §2.15.B.2 — command validation at the HTTP boundary only (parent chains followed) | `scripts/check-command-validation-placement.sh` *(moving here)* | lefthook `command-validation-placement`, CI `quality` |
| §2.15.C — the write-path chain's layer-skip bans | `scripts/check-write-path-chain.sh` *(moving here)* | lefthook `write-path-chain`, CI `quality` |

A new PHPStan rule lives in `PHPStan/` (namespace `Conventions\PHPStan\`), is registered under `rules:` in `phpstan.neon`, and is tested by a `RuleTestCase` over fixtures in `tests/Unit/Conventions/Fixtures/` (excluded from the main analysis — they exist to break the rule).
