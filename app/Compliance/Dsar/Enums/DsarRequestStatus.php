<?php

namespace App\Compliance\Dsar\Enums;

enum DsarRequestStatus: string
{
    case InProgress = 'in_progress';
    case Fulfilled = 'fulfilled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
