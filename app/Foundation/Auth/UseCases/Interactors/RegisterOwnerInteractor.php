<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Foundation\Auth\Aggregates\User;
use App\Foundation\Auth\UseCases\Results\RegisterResult;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;

/**
 * The fused owner-signup use case (system-architecture §3.2.1, Pattern A): every
 * aggregate of the registration commits in ONE transaction — a Company without an
 * owner, or a Subscription without a Company, must be impossible.
 */
class RegisterOwnerInteractor
{
    public function execute(RegisterOwnerCommand $command): RegisterResult
    {
        return DB::transaction(function () use ($command): RegisterResult {
            $userId = (string) Str::uuid7();
            $companyId = (string) Str::uuid7();

            User::retrieve($userId)->createOwner($companyId, $command)->persist();

            return new RegisterResult(userId: $userId, companyId: $companyId);
        });
    }
}
