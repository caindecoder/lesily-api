<?php

namespace App\Companies\Enums;

/**
 * The path an ownership transfer took (slice-11R spec §2): the owner's own
 * request, or a platform admin executing a verified case.
 */
enum TransferSource: string
{
    case SelfService = 'self_service';
    case Admin = 'admin';
}
