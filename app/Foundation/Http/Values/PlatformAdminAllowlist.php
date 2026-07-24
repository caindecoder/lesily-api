<?php

namespace App\Foundation\Http\Values;

/**
 * The one reading of `config('platform.admin_user_ids')`.
 *
 * `config/platform.php` hands over a bare `explode(',', env(...))`, so the raw
 * value is `['']` when the env var is absent and `[' uuid ']` when it is padded.
 * Normalising that is security-relevant — a blank segment must fail closed, a
 * padded one must still match — and until now the same three-line pipeline was
 * copy-pasted into `EnsurePlatformAdmin`, `SupportQueueNotificationReactor` and
 * (as of UC-1-113) `PlatformAdminQueueReactor`. Three copies of a rule that
 * decides who reaches the cross-tenant admin surface is three places to drift;
 * the third instance is the mechanical-rule trigger per
 * `feedback_defect-sweep-scope`.
 *
 * KNOWN GAP, deliberately not closed here: none of the previous copies
 * lowercased, though `config/platform.php` documents "LOWERCASE canonical
 * UUIDs". Changing the matching semantics of the admin gate is a behaviour
 * change to a security boundary and does not belong in a verify PR — tracked as
 * an OQ. This class preserves the existing semantics exactly.
 */
final class PlatformAdminAllowlist
{
    /**
     * @return list<string>
     */
    public static function userIds(): array
    {
        return array_values(array_filter(
            array_map(
                fn ($id): string => trim((string) $id),
                (array) config('platform.admin_user_ids', []),
            ),
            fn (string $id): bool => $id !== '',
        ));
    }

    public static function contains(?string $userId): bool
    {
        return in_array((string) $userId, self::userIds(), true);
    }
}
