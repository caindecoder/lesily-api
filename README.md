# lesily-api

Laravel 13 API backend for the Lesily HRMS SaaS platform (FusionRealm).

## Tech

- PHP 8.4 / Laravel 13
- MySQL
- Event-sourced (`spatie/laravel-event-sourcing`)
- API-only (no Blade/Inertia)
- OpenAPI 3.x spec via L5-Swagger — generation is guarded on the first path annotation (`scripts/generate-openapi-if-annotated.sh`); no endpoint carries one yet, so no spec is emitted today

## Layout

This repo is a sibling of `Documentation/`, `Lesily Design System/`, `lesily-web/`, and (later) `lesily-mobile/` under the workspace folder `D:\OneSyntax\Laravel Projects\Human Resource Management System\`. The workspace folder is itself a git repo, the private `caindecoder/lesily-workspace`, which holds `Documentation/` and ignores the nested repos, this one included; its README maps it.

## Branches

- `main` — active development; deploys to dev environment (when configured)
- `staging` — pre-production; deploys to `api-staging.lesily.com`
- `prod` — production; deploys to `api.lesily.com`

See `../Documentation/05-operations/git-workflow-plan.md` for the full branching strategy, merge style, multi-repo coordination, and branch protection rules.

## Conventions

See `../Documentation/05-operations/git-conventions.md` for commit message format, branch naming, and PR conventions.

## Build phase

Scaffolded with Laravel 13 + PHPUnit 12 + Larastan Level 8 + Pint + Lefthook + Infection (MSI ≥80%) + Telescope + Sentry + Boost on 2026-05-13 (chunk-1 TDD-RED beginning). See `../Documentation/06-design/chunk-1/foundation-brief.md` + the N+2 coding plan.

---
*© 2026 Chamil Abeysinghe | FusionRealm | @caindecoder*
