<?php

namespace App\Foundation\Http\Responses;

enum ApiErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case CompanyAccessDenied = 'COMPANY_ACCESS_DENIED';
    case FeatureNotAvailable = 'FEATURE_NOT_AVAILABLE';
    case RoleInsufficient = 'ROLE_INSUFFICIENT';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case EmailRegistered = 'EMAIL_REGISTERED';
    case BrNumberRegistered = 'BR_NUMBER_REGISTERED';
    case EpfNumberRegistered = 'EPF_NUMBER_REGISTERED';
    case UnsupportedCountry = 'UNSUPPORTED_COUNTRY';
    case InvalidSignature = 'INVALID_SIGNATURE';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
    case IdempotencyCacheUnavailable = 'IDEMPOTENCY_CACHE_UNAVAILABLE';
    case InternalError = 'INTERNAL_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated => 401,
            self::CompanyAccessDenied => 403,
            self::FeatureNotAvailable => 403,
            self::RoleInsufficient => 403,
            self::ResourceNotFound => 404,
            self::EmailRegistered => 409,
            self::BrNumberRegistered => 409,
            self::EpfNumberRegistered => 409,
            self::UnsupportedCountry => 422,
            self::InvalidSignature => 403,
            self::RateLimitExceeded => 429,
            self::IdempotencyKeyRequired => 422,
            self::IdempotencyCacheUnavailable => 503,
            self::InternalError => 500,
        };
    }
}
