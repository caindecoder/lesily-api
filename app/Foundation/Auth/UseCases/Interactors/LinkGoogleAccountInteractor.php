<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleAccountInUseException;
use App\Foundation\Auth\Exceptions\GoogleEmailMismatchException;
use App\Foundation\Billing\Exceptions\FeatureUnavailableException;
use App\Foundation\Auth\UseCases\Commands\LinkGoogleAccountCommand;

class LinkGoogleAccountInteractor
{
    public function __construct(
        protected GoogleIdTokenVerifier $googleIdTokenVerifier,
    ) {}

    public function execute(User $user, LinkGoogleAccountCommand $linkGoogleAccountCommand): void
    {
        $googleIdentity = $this->googleIdTokenVerifier->verify($linkGoogleAccountCommand->idToken);

        if (Str::lower($googleIdentity->email) !== Str::lower($user->email)) {
            throw GoogleEmailMismatchException::notTheUsersEmail();
        }

        if (! $googleIdentity->emailVerified) {
            throw GoogleEmailMismatchException::notVerifiedByGoogle();
        }

        if (! $user->hasCompanyThatCanUse(Feature::GoogleOAuth)) {
            throw FeatureUnavailableException::forFeature(Feature::GoogleOAuth);
        }

        try {
            DB::transaction(function () use ($user, $googleIdentity): void {
                UserAggregate::retrieve($user->getKey())->linkGoogleOAuth($googleIdentity->subject)->persist();
            });
        } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            // One Google account links to one user; the index answers even a concurrent link.
            throw str_contains($uniqueConstraintViolationException->getMessage(), 'users_google_id_unique')
                ? new GoogleAccountInUseException
                : $uniqueConstraintViolationException;
        }
    }
}
