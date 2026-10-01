<?php

namespace App\Compliance\Dsar\Enums;

enum DsarRequestStatus: string
{
    case InProgress = 'in_progress';
    case Fulfilled = 'fulfilled';
}
