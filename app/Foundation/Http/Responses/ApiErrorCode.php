<?php

namespace App\Foundation\Http\Responses;

use Illuminate\Http\Response;

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
    case SelfRoleChangeBlocked = 'SELF_ROLE_CHANGE_BLOCKED';
    case SelfRemovalBlocked = 'SELF_REMOVAL_BLOCKED';
    case TargetNotMember = 'TARGET_NOT_MEMBER';
    case SelfTransferBlocked = 'SELF_TRANSFER_BLOCKED';
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
    case StateGuardViolation = 'STATE_GUARD_VIOLATION';
    case ReceiptGenerationUnavailable = 'RECEIPT_GENERATION_UNAVAILABLE';
    case DsarInProgress = 'DSAR_IN_PROGRESS';
    case DsarRequesterMismatch = 'DSAR_REQUESTER_MISMATCH';
    case DsarNotReady = 'DSAR_NOT_READY';
    case HttpError = 'HTTP_ERROR';
    case InternalError = 'INTERNAL_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::Unauthenticated => Response::HTTP_UNAUTHORIZED,
            self::CompanyAccessDenied => Response::HTTP_FORBIDDEN,
            self::FeatureNotAvailable => Response::HTTP_FORBIDDEN,
            self::RoleInsufficient => Response::HTTP_FORBIDDEN,
            self::AlreadyMember => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::DeviceLimitReached => Response::HTTP_FORBIDDEN,
            self::TwoFactorSetupNotStarted => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::InvalidTwoFactorCode => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::TwoFactorAlreadyEnabled => Response::HTTP_CONFLICT,
            self::TwoFactorChallengeNotFound => Response::HTTP_NOT_FOUND,
            self::TwoFactorVerificationFailed => Response::HTTP_UNAUTHORIZED,
            self::TwoFactorNotEnabled => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::IncorrectPassword => Response::HTTP_UNAUTHORIZED,
            self::ResetLinkInvalid => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::InviteInvalid => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::InviteNotPending => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::InviteEmailMismatch => Response::HTTP_FORBIDDEN,
            self::OwnerTransferRequired => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::SelfRoleChangeBlocked => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::SelfRemovalBlocked => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::TargetNotMember => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::SelfTransferBlocked => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::ResourceNotFound => Response::HTTP_NOT_FOUND,
            self::EmailRegistered => Response::HTTP_CONFLICT,
            self::BrNumberRegistered => Response::HTTP_CONFLICT,
            self::EpfNumberRegistered => Response::HTTP_CONFLICT,
            self::UnsupportedCountry => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::InvalidSignature => Response::HTTP_FORBIDDEN,
            self::RateLimitExceeded => Response::HTTP_TOO_MANY_REQUESTS,
            self::IdempotencyKeyRequired => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::IdempotencyCacheUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            self::IdempotencyKeyConflict => Response::HTTP_CONFLICT,
            self::IdempotencyKeyProcessing => Response::HTTP_CONFLICT,
            self::StateGuardViolation => Response::HTTP_CONFLICT,
            self::ReceiptGenerationUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            self::DsarInProgress => Response::HTTP_CONFLICT,
            self::DsarRequesterMismatch => Response::HTTP_FORBIDDEN,
            self::DsarNotReady => Response::HTTP_CONFLICT,
            // Generic fallback for an unmapped HttpException. Production always
            // preserves the exception's real status via buildWithStatus(); this 500
            // is only the default required for match() exhaustiveness.
            self::HttpError => Response::HTTP_INTERNAL_SERVER_ERROR,
            self::InternalError => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
