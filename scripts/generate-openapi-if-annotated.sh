#!/bin/sh
# OpenAPI generation, guarded on a PATH annotation (VP2-5 R12, s218 - OQ-1-187).
#
# app/ carries #[OA\Info] + #[OA\Server] but no path annotation (#[OA\Get] etc.),
# so `php artisan l5-swagger:generate` fails ("Required @OA\PathItem() not found")
# and, since the scaffold (s037), the build job's `|| echo` fallback masked that
# failure on every run (the Rule-13 report-only class); openapi-diff's `|| true`
# generates were vacuous the same way. This script generates only when a path
# annotation exists - and then a generation failure fails the caller - otherwise
# it prints the true reason and exits 0. The first annotation increment
# (Decision 17, master-action #86) makes it live without a workflow edit.
#
# The pattern self-tests before any file is read (the OQ-1-185 doctrine: a dead or
# loosened matcher aborts with exit 2 and never reaches the skip branch).
# Callers: ci.yml build job; openapi-diff's two generate steps (copied to /tmp
# before the base checkout, because the base tree predates this script).
set -u

PATTERN='(#\[|@)OA\\(Get|Post|Put|Patch|Delete|Head|Options|PathItem)\('

printf '%s\n' '    #[OA\Get(path: "/api/v1/health")]' | grep -qE -- "$PATTERN" || { echo "openapi guard: self-test FAILED - the attribute form must match"; exit 2; }
printf '%s\n' ' * @OA\Post(' | grep -qE -- "$PATTERN" || { echo "openapi guard: self-test FAILED - the docblock form must match"; exit 2; }
printf '%s\n' '#[OA\Info(' | grep -qE -- "$PATTERN" && { echo "openapi guard: self-test FAILED - Info must not count"; exit 2; }
printf '%s\n' '#[OA\Server(' | grep -qE -- "$PATTERN" && { echo "openapi guard: self-test FAILED - Server must not count"; exit 2; }
[ -d app ] || { echo "openapi guard: no app/ directory here - run from the repo root"; exit 2; }

if grep -rqE -- "$PATTERN" app; then
    echo "openapi guard: path annotation found - generating"
    exec php artisan l5-swagger:generate
fi
echo "openapi guard: no OpenAPI path annotation in app/ yet (Decision 17 / master-action #86) - generation not attempted"
exit 0
