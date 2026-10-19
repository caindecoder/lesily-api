<?php

namespace Tests\Feature\Foundation\AccessLog;

use Closure;
use Throwable;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\Auth\Exceptions\IncorrectPasswordException;
use App\Foundation\Auth\Exceptions\DeviceLimitReachedException;
use App\Foundation\Auth\Exceptions\InvalidCredentialsException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use App\Foundation\Http\Exceptions\CompanyAccessDeniedException;
use App\Compliance\Dsar\Exceptions\DsarRequesterMismatchException;
use App\Foundation\Auth\Exceptions\LoginChallengeNotFoundException;
use App\Companies\Memberships\Exceptions\InviteEmailMismatchException;
use App\Foundation\Auth\Exceptions\TwoFactorVerificationFailedException;

class KeepRecordedRefusalsOutOfTheErrorLogTest extends FoundationTestCase
{
    /** @param  Closure(): Throwable  $refusal */
    #[Test]
    #[DataProvider('provideRecordedRefusals')]
    public function when_a_recorded_refusal_is_raised_then_it_is_not_reported(Closure $refusal): void
    {
        // Given reports are captured
        Exceptions::fake();
        $exception = $refusal();

        // When the refusal is reported
        report($exception);

        // Then it never reaches the error log
        Exceptions::assertNotReported($exception::class);
    }

    /** @return array<string, array{Closure(): Throwable}> */
    public static function provideRecordedRefusals(): array
    {
        return [
            'invalid credentials' => [fn () => new InvalidCredentialsException],
            'two-factor verification failed' => [fn () => new TwoFactorVerificationFailedException],
            'incorrect password' => [fn () => new IncorrectPasswordException],
            'device limit reached' => [fn () => new DeviceLimitReachedException],
            'company access denied' => [fn () => new CompanyAccessDeniedException],
            'feature unavailable' => [fn () => new FeatureUnavailableException('Pro', '/settings/billing/upgrade')],
            'role insufficient' => [fn () => new RoleInsufficientException('companies.update')],
            'invite email mismatch' => [fn () => new InviteEmailMismatchException],
            'DSAR requester mismatch' => [fn () => new DsarRequesterMismatchException],
            'login challenge not found' => [fn () => new LoginChallengeNotFoundException],
        ];
    }
}
