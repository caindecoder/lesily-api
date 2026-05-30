<?php

namespace App\Foundation\Http\Responses;

enum ApiErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case FeatureNotAvailable = 'FEATURE_NOT_AVAILABLE';
    case RoleInsufficient = 'ROLE_INSUFFICIENT';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case InternalError = 'INTERNAL_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::FeatureNotAvailable => 403,
            self::RoleInsufficient => 403,
            self::ResourceNotFound => 404,
            self::RateLimitExceeded => 429,
            self::InternalError => 500,
        };
    }
}
