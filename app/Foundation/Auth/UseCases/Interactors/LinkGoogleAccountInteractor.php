<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleAccountInUseException;
use App\Foundation\Auth\Exceptions\GoogleEmailMismatchException;
use App\Foundation\Billing\Exceptions\FeatureUnavailableException;
use App\Foundation\Auth\UseCases\Commands\LinkGoogleAccountCommand;

class LinkGoogleAccountInteractor
{
    public function __construct(
        protected GoogleIdTokenVerifier $verifier,
    ) {}

    public function execute(User $user, LinkGoogleAccountCommand $command): void
    {
        $identity = $this->verifier->verify($command->idToken);

        if (Str::lower($identity->email) !== Str::lower($user->email)) {
            throw GoogleEmailMismatchException::notTheUsersEmail();
        }

        if (! $identity->emailVerified) {
            throw GoogleEmailMismatchException::notVerifiedByGoogle();
        }

        if (! $this->hasCompanyWithGoogleSignIn($user)) {
            throw FeatureUnavailableException::forFeature(Feature::GoogleOAuth);
        }

        DB::transaction(function () use ($user, $identity): void {
            $aggregate = UserAggregate::retrieve($user->getKey())->linkGoogleOAuth($identity->subject);

            if (User::query()->where('google_id', $identity->subject)->exists()) {
                throw new GoogleAccountInUseException;
            }

            $aggregate->persist();
        });
    }

    protected function hasCompanyWithGoogleSignIn(User $user): bool
    {
        return $user->companies()->with('addons')->get()
            ->contains(fn (Company $company): bool => $company->canUseFeature(Feature::GoogleOAuth));
    }
}
