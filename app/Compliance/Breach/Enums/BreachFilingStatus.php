<?php

namespace App\Compliance\Breach\Enums;

enum BreachFilingStatus: string
{
    case NotRequested = 'not_requested';
    case Requested = 'requested';
    case Filed = 'filed';
}
