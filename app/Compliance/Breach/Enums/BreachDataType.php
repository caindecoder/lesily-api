<?php

namespace App\Compliance\Breach\Enums;

enum BreachDataType: string
{
    case Pii = 'pii';
    case Payroll = 'payroll';
    case Banking = 'banking';
    case Nic = 'nic';
    case EpfEtf = 'epf_etf';
    case Medical = 'medical';
    case Other = 'other';
}
