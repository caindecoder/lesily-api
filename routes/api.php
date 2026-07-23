<?php

use Illuminate\Support\Facades\Route;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use App\Companies\IO\Http\Controllers\CompaniesController;
use App\Foundation\Auth\IO\Http\Controllers\LoginController;
use App\Foundation\Auth\IO\Http\Controllers\LogoutController;
use App\Foundation\Auth\IO\Http\Controllers\ProfileController;
use App\Foundation\Auth\IO\Http\Controllers\RegisterController;
use App\Billing\Invoices\IO\Http\Controllers\InvoicesController;
use App\Foundation\Auth\IO\Http\Controllers\LogoutAllController;
use App\Billing\Subscriptions\IO\Http\Controllers\PlansController;
use App\Foundation\Auth\IO\Http\Controllers\MyCompaniesController;
use App\Foundation\Auth\IO\Http\Controllers\ViewSessionsController;
use App\Foundation\Auth\IO\Http\Controllers\ResetPasswordController;
use App\Foundation\Auth\IO\Http\Controllers\RevokeSessionController;
use App\Foundation\Health\IO\Http\Controllers\HealthCheckController;
use App\Foundation\Auth\IO\Http\Controllers\ChangePasswordController;
use App\Foundation\Auth\IO\Http\Controllers\DefaultCompanyController;
use App\Foundation\Auth\IO\Http\Controllers\ForgotPasswordController;
use App\Foundation\Auth\IO\Http\Controllers\EnableTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\VerifyTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\ConfirmTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\DisableTwoFactorController;
use App\Companies\Memberships\IO\Http\Controllers\InvitationsController;
use App\Companies\Memberships\IO\Http\Controllers\MembershipsController;
use App\Foundation\Auth\IO\Http\Controllers\EmailVerificationController;
use App\Billing\Subscriptions\IO\Http\Controllers\SubscriptionsController;
use App\Billing\Invoices\IO\Http\Controllers\PlatformAdminInvoicesController;
use App\Companies\Memberships\IO\Http\Controllers\AcceptInvitationController;
use App\Foundation\Auth\IO\Http\Controllers\RegenerateRecoveryCodesController;
use App\Companies\OwnershipTransfers\IO\Http\Controllers\OwnershipTransfersController;
use App\Companies\OwnershipTransfers\IO\Http\Controllers\PlatformAdminOwnershipTransfersController;

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

// View own profile (UC-1-014) — the authenticated user's own account profile, a
// direct principal read (no interactor, no repository — s125 /simplicity-check P1).
// Bare auth:sanctum: a `me/` self-service read (no company context), no throttle
// (tiered limits deferred, OQ-1-098). Read-only, no domain event.
Route::get('/v1/me', [ProfileController::class, 'show'])
    ->middleware('auth:sanctum');

// Update own profile (UC-1-015) — name + notification locale, full-PUT semantics,
// event-sourced via the built User aggregate (slice-profile B5). Bare auth:sanctum:
// a `me/` self-service write (no company context), no throttle (tiered limits
// deferred, OQ-1-098) and no idempotency-key mount (naturally idempotent full-PUT;
// a retry re-converges and the no-op guard records no duplicate event — the
// default-company precedent, s127 ruling).
Route::put('/v1/me', [ProfileController::class, 'update'])
    ->middleware('auth:sanctum');

// List my companies (UC-1-034) — the authenticated user's own live company
// memberships, the switcher-list read. Bare auth:sanctum: a `me/` self-service
// read (no company context — the memberships span companies), no throttle
// (tiered limits deferred, OQ-1-098). Live pivots only; switching itself is
// client-side via X-Company-Context (the designed switch endpoint is superseded).
Route::get('/v1/me/companies', MyCompaniesController::class)
    ->middleware('auth:sanctum');

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

// Default company (UC-1-139) — the persisted switcher preference, a plain
// users.default_company_id write (NOT event-sourced — slice-companies B2).
// Bare auth:sanctum: a `me/` self-service preference with no credential check
// (no throttle — not a brute-force surface) and no idempotency-key mount (both
// verbs are naturally idempotent; the 204 carries no body). The set is guarded
// to live memberships in the interactor, collapsed to 422 (no id-existence leak).
Route::put('/v1/me/default-company', [DefaultCompanyController::class, 'update'])
    ->middleware('auth:sanctum');
Route::delete('/v1/me/default-company', [DefaultCompanyController::class, 'destroy'])
    ->middleware('auth:sanctum');

// View company details (UC-1-032) — the first route-param company endpoint:
// the resolver reads {companyId} first, header fallback (slice-companies R8;
// the URL is authoritative here). Repository-direct read, no event. Gate order
// per api-conventions §7: auth → company-scope → permission (companies.view is
// seeded to every role except manager). No throttle (tiered limits — OQ-1-098).
Route::get('/v1/companies/{companyId}', [CompaniesController::class, 'show'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:companies.view']);

// Update company details (UC-1-033) — Owner/Admin edit name + settings through
// the event-sourced Company aggregate (CompanyDetailsUpdated + CompanySettingsUpdated).
// Same param-first resolver + company-scope gate as the view; permission:companies.update
// is owner/admin only. Immutable fields (br/epf/country) are refused by the command
// whitelist (422). No idempotency-key — naturally idempotent, and the sibling company/
// membership writes omit it (s122 ruling). No throttle (tiered limits — OQ-1-098).
Route::put('/v1/companies/{companyId}', [CompaniesController::class, 'update'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:companies.update']);

// Invite a user to the company (UC-1-018) — Owner/Admin/HR-Officer email an invite;
// a pending invitation is created and the invite email dispatched inline. auth:sanctum
// -> auth.company -> permission:users.invite (D4 — seeded to owner/admin/hr_officer)
// -> throttle:invitations (20/hr per actor, D9).
Route::post('/v1/invitations', [InvitationsController::class, 'store'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:users.invite', 'throttle:invitations']);

// Resend rotates the token + resets the 7-day expiry on a live pending invitation
// (UC-1-021). Shares the invitations mail-spam budget with invite (R5 session 110).
Route::post('/v1/invitations/{invitationId}/resend', [InvitationsController::class, 'resend'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:users.invite', 'throttle:invitations']);

// Revoke a pending invitation (UC-1-022) — the token is dead thereafter; accepted/
// revoked/expired collapse to 422 INVITE_NOT_PENDING (R-B s111). No throttle: revoke
// sends no mail, so it stays outside the invitations spam budget (R-D s111).
Route::delete('/v1/invitations/{invitationId}', [InvitationsController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:users.revoke-invitation']);

// View the company roster (UC-1-017) — active memberships with user + role, paginated
// per api-conventions §9. Read-only (no aggregate/event — cd-users §read-only-via-repository).
Route::get('/v1/memberships', [MembershipsController::class, 'index'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:users.view']);

// Change a member's role (UC-1-023) — event-sourced UserMembershipRoleChanged updates
// company_user.role_id. Owner-role target → 422 OWNER_TRANSFER_REQUIRED; self-change →
// 422 SELF_ROLE_CHANGE_BLOCKED (R-A/R-B s112). No throttle (R-H s112 — revoke parity).
Route::patch('/v1/memberships/{membershipId}', [MembershipsController::class, 'update'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:users.change-role']);

// Remove a member from the company (UC-1-024) — event-sourced UserRemovedFromCompany
// soft-deletes the company_user pivot + revokes the removed member's sessions. Owner
// target → 422 OWNER_TRANSFER_REQUIRED; self → 422 SELF_REMOVAL_BLOCKED (self before
// owner). No throttle (R-H s112 — revoke parity).
Route::delete('/v1/memberships/{membershipId}', [MembershipsController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:users.remove']);

// Accept an invitation (UC-1-019) — guest-or-authenticated: the emailed single-use token
// authenticates the new-user path; an existing-email invitee must additionally hold a
// Sanctum session (R9/D7). Deliberately NO auth middleware (it would 401 guests; the
// delivery layer resolves auth('sanctum') optionally). throttle:auth-tier guards token
// guessing. Every token failure collapses to 422 INVITE_INVALID (H4 session 109).
Route::post('/v1/invitations/accept/{token}', AcceptInvitationController::class)
    ->middleware(['throttle:auth-tier']);

// Request an ownership transfer (UC-1-028, SM-1-05 T1) — owner-only per the
// 2026-05-24 matrix Module 8 (`ownership-transfer.request`); the request is
// support-verified (UC-1-113) before any privilege change (UC-1-093).
Route::post('/v1/ownership-transfers', [OwnershipTransfersController::class, 'store'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:ownership-transfer.request', 'idempotency-key:required']);

// Tenant transfer reads (UC-1-126 history / UC-1-125 status) — Owner + Admin +
// Auditor per the 2026-05-24 matrix Module 8 (`view-history` / `view-status`),
// company-scoped; a cross-tenant id is existence-hidden (404).
Route::get('/v1/ownership-transfers', [OwnershipTransfersController::class, 'index'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:ownership-transfer.view-history']);

Route::get('/v1/ownership-transfers/{requestId}', [OwnershipTransfersController::class, 'show'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:ownership-transfer.view-status']);

// The plan catalog (UC-1-042 partial — the plan-picker read, slice-plans-billing T1).
// Enum-derived static read, non-paginated {data: [...]} (MyCompaniesCollection
// precedent); permission:billing.view per the 2026-05-24 matrix (owner full
// [view+manage], admin/auditor read-only). Repository-free — no interactor (cd-subscriptions §5, gate I1).
// No throttle (tiered limits — OQ-1-098).
Route::get('/v1/plans', [PlansController::class, 'index'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:billing.view']);

// The company's subscription state (UC-1-041, slice-plans-billing T2). Singular —
// every company has exactly one Subscription (api-1-subscriptions §2). Pure read,
// no interactor (cd-subscriptions §5, gate I1). No throttle (tiered — OQ-1-098).
Route::get('/v1/subscription', [SubscriptionsController::class, 'show'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:billing.view']);

// The company's invoice history (UC-1-120, slice-plans-billing T4) — paginated
// per api-conventions §9, filter whitelist per §10.1 (unknown params 422 — the
// s134 F-a ruling covers company_id tenant-override attempts too). Pure read,
// no interactor (cd-invoices §5, gate I1). No throttle (tiered — OQ-1-098).
Route::get('/v1/invoices', [InvoicesController::class, 'index'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:billing.view']);

// A paid invoice's PDF receipt (UC-1-121, slice-plans-billing T5) — regenerated
// on demand, never stored (R3); status ∈ {paid, refunded} AND no open dispute
// (s129 F7); the ONE binary-response endpoint in chunk-1 (api-1-invoices §4.5).
// Pure read, no interactor (cd-invoices §5, gate I1). No throttle (OQ-1-098).
Route::get('/v1/invoices/{invoiceId}/receipt', [InvoicesController::class, 'receipt'])
    ->middleware(['auth:sanctum', 'auth.company', 'permission:billing.view']);

// A platform admin's cross-tenant invoice review (UC-1-122, slice-plans-billing
// T7) — the FIRST real /api/v1/admin/* route: auth:sanctum + platform.admin
// (B2 INTERIM allowlist; 404 no-info-leak, never 403), deliberately NO
// auth.company (cross-tenant by design). Thin interactor — the audit write is
// persistence (s129 R8/E07): ONE audit_trail row on 200, none on 404.
// No throttle (tiered — OQ-1-098).
Route::get('/v1/admin/invoices/{invoiceId}', [PlatformAdminInvoicesController::class, 'show'])
    ->middleware(['auth:sanctum', 'platform.admin']);

// The platform admin's cross-tenant ownership-transfer triage queue (UC-1-127,
// slice #11 Task 3) — same admin chain as the invoice review above, no
// auth.company, no permission: gate (the admin family carries no company
// context, and matrix line 185 keeps admin reads on the allowlist rather than a
// seeded permission). Ordered soonest-deadline-first, open states by default.
Route::get('/v1/admin/ownership-transfers', [PlatformAdminOwnershipTransfersController::class, 'index'])
    ->middleware(['auth:sanctum', 'platform.admin']);
