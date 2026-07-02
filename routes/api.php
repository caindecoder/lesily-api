<?php

use Illuminate\Support\Facades\Route;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use App\Foundation\Auth\IO\Http\Controllers\LoginController;
use App\Foundation\Auth\IO\Http\Controllers\LogoutController;
use App\Foundation\Auth\IO\Http\Controllers\RegisterController;
use App\Foundation\Auth\IO\Http\Controllers\LogoutAllController;
use App\Foundation\Auth\IO\Http\Controllers\ViewSessionsController;
use App\Foundation\Auth\IO\Http\Controllers\ResetPasswordController;
use App\Foundation\Auth\IO\Http\Controllers\RevokeSessionController;
use App\Foundation\Health\IO\Http\Controllers\HealthCheckController;
use App\Foundation\Auth\IO\Http\Controllers\ChangePasswordController;
use App\Foundation\Auth\IO\Http\Controllers\ForgotPasswordController;
use App\Foundation\Auth\IO\Http\Controllers\EnableTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\VerifyTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\ConfirmTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\DisableTwoFactorController;
use App\Companies\Memberships\IO\Http\Controllers\InvitationsController;
use App\Foundation\Auth\IO\Http\Controllers\EmailVerificationController;
use App\Foundation\Auth\IO\Http\Controllers\RegenerateRecoveryCodesController;

// Health is operational tooling and opts out of the platform cross-cutting
// middleware (no Correlation-Id) — UC-1-118 / cd-foundation-http §4.2.
Route::get('/health', HealthCheckController::class)
    ->withoutMiddleware(AssignCorrelationId::class);

// Owner signup — public per api-1-auth-onboarding §register, under the auth-tier
// per-IP limit (10/min, defined in AppServiceProvider). Idempotency is
// deliberately NOT mounted here (OQ-1-114 C-iii): the 2xx body carries a
// plaintext API token, which the idempotency response cache would hold at rest
// for 24h. Concurrent duplicates are data-safe via the DB-uniqueness 409
// backstop (OQ-1-111); mount idempotency here only once token caching is resolved.
Route::post('/v1/auth/register', RegisterController::class)
    ->middleware('throttle:auth-tier');

// Email+password login (UC-1-002) — public per api-1-auth-onboarding §login, under
// the same auth-tier per-IP limit (10/min) as register. Token-only (cd-auth token-only
// banner): the credential check issues a Sanctum token, no domain event.
Route::post('/v1/auth/login', LoginController::class)
    ->middleware('throttle:auth-tier');

// Forgot password (UC-1-008) — a guest requests a reset link. Public; enumeration-safe (always
// 204 whether or not the email is registered). throttle:forgot-password = 3/15min keyed by the
// TARGET email (tighter than auth-tier), so a reset link can't be spammed to a victim's inbox.
Route::post('/v1/auth/forgot-password', ForgotPasswordController::class)
    ->middleware('throttle:forgot-password');

// Reset password (UC-1-008) — a guest sets a new password via the emailed single-use token.
// Public: the token IS the credential. throttle:auth-tier (the shared 10/min/IP public-auth limit)
// bounds the brute-force surface. The broker validates the token (60-min expiry / single-use), then
// the write is event-sourced (UserPasswordReset -> UserProjector) and ALL of the user's sessions are
// revoked (they re-log in with the new password). No idempotency — the single-use token is the dedup.
Route::post('/v1/auth/reset-password', ResetPasswordController::class)
    ->middleware('throttle:auth-tier');

// Log out (UC-1-004) — revoke the CURRENT Sanctum token only (not other devices;
// logout-all is UC-1-011, deferred). Token-only (cd-auth §5): a thin controller
// deletes the access token, no domain event. Bare auth:sanctum, no throttle — the
// slice's authoritative contract (the api-doc's tenant-tier row predates this and
// has no limiter to bind to; reconciled at slice close). LeaveDates parity: its
// auth-gated token-destroy is likewise un-throttled.
Route::post('/v1/auth/logout', LogoutController::class)
    ->middleware('auth:sanctum');

// Log out all other devices (UC-1-011) — password re-auth, then revoke every one of the
// user's Sanctum tokens EXCEPT the current. throttle:auth-tier AFTER auth:sanctum: the
// interactor runs Hash::check, so this is a brute-force/password-guessing surface like
// /2fa disable+confirm (NOT a credential-free action like /logout, which is bare auth:sanctum).
// Token-table side-effect, no domain event (slice §2; api-doc tenant-tier row reconciled s099).
Route::post('/v1/auth/logout-all', LogoutAllController::class)
    ->middleware(['auth:sanctum', 'throttle:auth-tier']);

// Email verification (UC-1-001 alt-6a) — the signed link IS the credential (no
// auth token); `hash` = sha1(user email) per api-1-auth-onboarding. No route is
// gated on verified status this slice (no enforcement).
Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:auth-tier'])
    ->name('verification.verify');

// Set up 2FA — enable step (UC-1-006), Pro+ via the feature gate. Generates a TOTP
// secret + otpauth:// URI and stages it in a short-TTL cache; nothing persists to the
// users row until /2fa/confirm verifies a code. auth:sanctum -> auth.company ->
// feature:two_factor_auth (no permission gate — self-service, all roles per UC-1-006).
Route::post('/v1/auth/2fa/enable', EnableTwoFactorController::class)
    ->middleware(['auth:sanctum', 'auth.company', 'feature:two_factor_auth']);

// Confirm 2FA — verify the TOTP code against the staged secret (UC-1-006 step 5),
// then enable 2FA and return the one-time recovery codes. Same gate as enable, plus
// throttle:auth-tier (brute-force surface — the code is being checked here).
Route::post('/v1/auth/2fa/confirm', ConfirmTwoFactorController::class)
    ->middleware(['auth:sanctum', 'auth.company', 'feature:two_factor_auth', 'throttle:auth-tier']);

// Verify 2FA at login (UC-1-005) — the second factor. Public: the opaque challenge_id
// (issued by the login 202, alt-4a) is the auth proof, consumed here. Token-only — mints
// the Sanctum token, records no UserLoggedIn (cd-auth token-only). throttle:auth-tier
// guards the per-IP brute-force surface; the per-challenge 5-attempt cap lives in the store.
Route::post('/v1/auth/2fa/verify', VerifyTwoFactorController::class)
    ->middleware('throttle:auth-tier');

// Disable 2FA (UC-1-007) — password re-auth. throttle:auth-tier AFTER auth:sanctum: the
// interactor runs Hash::check, so this is a brute-force surface like /2fa/confirm and
// /2fa/verify (a stolen token must not become an unthrottled password-guessing oracle) —
// NOT a credential-free action like logout/enable (slice R9 reconciled). On success it
// wipes the secret + recovery codes and records a non-secret User2FADisabled marker → 204.
Route::delete('/v1/auth/2fa', DisableTwoFactorController::class)
    ->middleware(['auth:sanctum', 'throttle:auth-tier']);

// Regenerate 2FA recovery codes (UC-1-137) — password re-auth, keeps the TOTP secret,
// rotates the 10 recovery codes (old set invalidated). throttle:auth-tier (credential
// surface like disable + confirm — R9). 200 with the new codes shown once.
Route::post('/v1/auth/2fa/recovery-codes', RegenerateRecoveryCodesController::class)
    ->middleware(['auth:sanctum', 'throttle:auth-tier']);

// View active sessions (UC-1-010) — the authenticated user's own non-expired
// Sanctum tokens. Bare auth:sanctum: a `me/` self-service read (no company
// context), no throttle (a read with no credential check isn't a brute-force
// surface; LeaveDates' PAT list is likewise un-throttled — the api-doc's
// tenant-tier row predates this and has no registered limiter; reconciled at
// slice close). Read-only token-table query, no domain event.
Route::get('/v1/me/sessions', ViewSessionsController::class)
    ->middleware('auth:sanctum');

// Revoke a single session (UC-1-010 manage-sessions surface) — hard-delete one of the
// user's own Sanctum tokens by id. User-scoped: an unknown or another user's token id
// matches nothing and 404s (existence hidden), the foreign row left intact. Bare
// auth:sanctum, no throttle — same self-service `me/` contract as the list above (the
// api-doc's tenant-tier row has no registered limiter; reconciled at slice close).
// Token-table side-effect, no domain event (slice §2; /simplicity-check 2026-06-27 inline).
// whereNumber pins {tokenId} to digits so a malformed id (e.g. 12abc) 404s at routing rather
// than MySQL-coercing the BIGINT id compare onto a real id and deleting the wrong own token.
Route::delete('/v1/me/sessions/{tokenId}', RevokeSessionController::class)
    ->middleware('auth:sanctum')
    ->whereNumber('tokenId');

// Change own password (UC-1-016) — authenticated self-service credential change at the
// REST/Fortify-standard PUT /v1/me/password (the /v1/me/* self-service surface). throttle:auth-tier
// AFTER auth:sanctum: the interactor runs Hash::check, a brute-force surface like /2fa disable +
// logout-all. idempotency-key:required — the first route to mount it, safe because the 204 carries
// no sensitive body (unlike register, OQ-1-114). On success the password write is event-sourced
// (UserPasswordChanged -> UserProjector) and every OTHER session is revoked; the current survives -> 204.
Route::put('/v1/me/password', ChangePasswordController::class)
    ->middleware(['auth:sanctum', 'throttle:auth-tier', 'idempotency-key:required']);

// Invite a user to the company (UC-1-018) — Owner/Admin/HR-Officer email an invite;
// a pending invitation is created and the invite email dispatched inline. auth:sanctum
// -> auth.company -> permission:users.invite (D4 — seeded to owner/admin/hr_officer).
// The throttle (invitations) gate arrives with its driving test (RED-first).
Route::post('/v1/invitations', [InvitationsController::class, 'store'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:users.invite']);
