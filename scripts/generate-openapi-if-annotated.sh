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
# loosened matcher aborts with exit 2 and never reaches the skip branch): every
# path verb in the attribute form, the docblock form, the grouped form
# (`#[OA\Tag, OA\Get(`) and the FQCN form must match; Info / Server / Response
# must not. An imported bare `#[Get(` is unsupported by design (the house style is
# `use OpenApi\Attributes as OA;`, Controller.php). A grep error (exit 2, e.g. an
# unreadable file) is neither a match nor a skip: it aborts with exit 2.
# Callers: ci.yml build job; openapi-diff's two generate steps (copied to /tmp
# before the base checkout, because the base tree predates this script).
set -u

PATTERN='(OA|OpenApi\\Attributes)\\(Get|Post|Put|Patch|Delete|Head|Options|PathItem)\('

for verb in Get Post Put Patch Delete Head Options PathItem; do
    printf '%s\n' "    #[OA\\${verb}(path: \"/api/v1/x\")]" | grep -qE -- "$PATTERN" || { echo "openapi guard: self-test FAILED - the attribute form must match ${verb}"; exit 2; }
done
printf '%s\n' ' * @OA\Post(' | grep -qE -- "$PATTERN" || { echo "openapi guard: self-test FAILED - the docblock form must match"; exit 2; }
printf '%s\n' '#[OA\Tag(name: "x"), OA\Get(path: "/x")]' | grep -qE -- "$PATTERN" || { echo "openapi guard: self-test FAILED - the grouped form must match"; exit 2; }
printf '%s\n' '#[\OpenApi\Attributes\Get(path: "/x")]' | grep -qE -- "$PATTERN" || { echo "openapi guard: self-test FAILED - the FQCN form must match"; exit 2; }
for not in Info Server Response; do
    printf '%s\n' "#[OA\\${not}(" | grep -qE -- "$PATTERN" && { echo "openapi guard: self-test FAILED - ${not} must not count"; exit 2; }
done
[ -d app ] || { echo "openapi guard: no app/ directory here - run from the repo root"; exit 2; }

grep -rqE -- "$PATTERN" app
status=$?
case "$status" in
    0)
        echo "openapi guard: path annotation found - generating"
        exec php artisan l5-swagger:generate
        ;;
    1)
        echo "openapi guard: no OpenAPI path annotation in app/ yet (Decision 17 / master-action #86) - generation not attempted"
        exit 0
        ;;
    *)
        echo "openapi guard: grep failed with status ${status} - not a skip"
        exit 2
        ;;
esac
