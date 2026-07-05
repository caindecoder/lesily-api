<?php

namespace App\Foundation\Http\Responses;

enum ApiErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case CompanyAccessDenied = 'COMPANY_ACCESS_DENIED';
    case FeatureNotAvailable = 'FEATURE_NOT_AVAILABLE';
    case RoleInsufficient = 'ROLE_INSUFFICIENT';
    case AlreadyMember = 'ALREADY_MEMBER';
    case DeviceLimitReached = 'DEVICE_LIMIT_REACHED';
    case TwoFactorSetupNotStarted = 'TWO_FACTOR_SETUP_NOT_STARTED';
    case InvalidTwoFactorCode = 'INVALID_TWO_FACTOR_CODE';
    case TwoFactorAlreadyEnabled = 'TWO_FACTOR_ALREADY_ENABLED';
    case TwoFactorChallengeNotFound = 'TWO_FACTOR_CHALLENGE_NOT_FOUND';
    case TwoFactorVerificationFailed = 'TWO_FACTOR_VERIFICATION_FAILED';
    case TwoFactorNotEnabled = 'TWO_FACTOR_NOT_ENABLED';
    case IncorrectPassword = 'INCORRECT_PASSWORD';
    case ResetLinkInvalid = 'RESET_LINK_INVALID';
    case InviteInvalid = 'INVITE_INVALID';
    case InviteNotPending = 'INVITE_NOT_PENDING';
    case InviteEmailMismatch = 'INVITE_EMAIL_MISMATCH';
    case OwnerTransferRequired = 'OWNER_TRANSFER_REQUIRED';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case EmailRegistered = 'EMAIL_REGISTERED';
    case BrNumberRegistered = 'BR_NUMBER_REGISTERED';
    case EpfNumberRegistered = 'EPF_NUMBER_REGISTERED';
    case UnsupportedCountry = 'UNSUPPORTED_COUNTRY';
    case InvalidSignature = 'INVALID_SIGNATURE';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
    case IdempotencyCacheUnavailable = 'IDEMPOTENCY_CACHE_UNAVAILABLE';
    case IdempotencyKeyConflict = 'IDEMPOTENCY_KEY_CONFLICT';
    case IdempotencyKeyProcessing = 'IDEMPOTENCY_KEY_PROCESSING';
    case HttpError = 'HTTP_ERROR';
    case InternalError = 'INTERNAL_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated => 401,
            self::CompanyAccessDenied => 403,
            self::FeatureNotAvailable => 403,
            self::RoleInsufficient => 403,
            self::AlreadyMember => 422,
            self::DeviceLimitReached => 403,
            self::TwoFactorSetupNotStarted => 422,
            self::InvalidTwoFactorCode => 422,
            self::TwoFactorAlreadyEnabled => 409,
            self::TwoFactorChallengeNotFound => 404,
            self::TwoFactorVerificationFailed => 401,
            self::TwoFactorNotEnabled => 422,
            self::IncorrectPassword => 401,
            self::ResetLinkInvalid => 422,
            self::InviteInvalid => 422,
            self::InviteNotPending => 422,
            self::InviteEmailMismatch => 403,
            self::OwnerTransferRequired => 422,
            self::ResourceNotFound => 404,
            self::EmailRegistered => 409,
            self::BrNumberRegistered => 409,
            self::EpfNumberRegistered => 409,
            self::UnsupportedCountry => 422,
            self::InvalidSignature => 403,
            self::RateLimitExceeded => 429,
            self::IdempotencyKeyRequired => 422,
            self::IdempotencyCacheUnavailable => 503,
            self::IdempotencyKeyConflict => 409,
            self::IdempotencyKeyProcessing => 409,
            // Generic fallback for an unmapped HttpException. Production always
            // preserves the exception's real status via buildWithStatus(); this 500
            // is only the default required for match() exhaustiveness.
            self::HttpError => 500,
            self::InternalError => 500,
        };
    }
}
