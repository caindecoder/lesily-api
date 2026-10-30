<?php

namespace App\Foundation\Auth\UseCases;

use App\Models\User;
use App\Foundation\AccessLog\Enums\AccessEventReason;

/** A sign-in that has passed every check and only needs finishing (SignInFinisher). */
class PendingSession
{
    public function __construct(
        public readonly User $user,
        public readonly string $deviceName,
        public readonly ?AccessEventReason $factor = null,
        public readonly ?string $googleSubjectToLink = null,
    ) {}
}
