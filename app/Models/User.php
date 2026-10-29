<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use App\Companies\Entities\Company;
use Database\Factories\UserFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Foundation\Auth\Notifications\PasswordResetLinkNotification;
use App\Foundation\Auth\Notifications\EmailVerificationLinkNotification;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;

/**
 * @property string $locale
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_logged_at
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $google_id
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])]
class User extends Authenticatable implements CanResetPassword, HasLocalePreference, MustVerifyEmail
{
    use CanResetPasswordTrait;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasUuids;

    /**
     * The locale notifications and emails to this user are rendered in
     * (Sinhala / Tamil / English) — trilingual on all plans per S16.
     */
    public function preferredLocale(): string
    {
        return $this->locale;
    }

    /**
     * Route the "reset your password" link through our queued, web-app-targeted notification
     * (UC-1-008) instead of Laravel's default. The broker passes the plain single-use token.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetLinkNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new EmailVerificationLinkNotification);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_logged_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * The companies this user is a member of — the company_user pivot, the inverse of
     * Company::users(). The device-limit guard reads each membership's
     * Company::planInForce() to decide whether the per-account concurrent-device cap
     * applies (UC-1-002 alt-5a). wherePivotNull: a removed (soft-deleted, UC-1-024)
     * membership is not a live membership — belongsToMany reads must filter the pivot
     * column explicitly (the CompanyUser SoftDeletes global scope does not reach it). R7.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user', 'user_id', 'company_id')
            ->wherePivotNull('deleted_at');
    }

    /**
     * The company label for account-level notices (OQ-1-124): the default company's name while
     * a live membership backs it (both reads go through companies(), so a removed membership's
     * default falls through), else the oldest live membership's — deterministic (pivot
     * created_at asc, id asc tiebreak) — else '' for a company-less user (degrade, never fail).
     */
    public function notificationCompanyName(): string
    {
        $defaultName = $this->default_company_id === null
            ? null
            : $this->companies()->whereKey($this->default_company_id)->value('name');

        return (string) ($defaultName ?? $this->companies()
            ->orderByPivot('created_at')
            ->orderByPivot('id')
            ->value('name'));
    }

    /**
     * The default-company preference, only while a live membership backs it (UC-1-014).
     * users.default_company_id survives a membership removal (UC-1-024 soft-deletes the
     * pivot without clearing the column), so readers guard at read time — the same
     * convention as notificationCompanyName and the companies-list is_default flag.
     */
    public function liveDefaultCompanyId(): ?string
    {
        if ($this->default_company_id === null) {
            return null;
        }

        return $this->companies()->whereKey($this->default_company_id)->exists()
            ? $this->default_company_id
            : null;
    }
}
