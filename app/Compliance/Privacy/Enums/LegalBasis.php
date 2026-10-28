<?php

namespace App\Compliance\Privacy\Enums;

enum LegalBasis: string
{
    case Contract = 'contract';
    case LegalObligation = 'legal_obligation';
    case LegitimateInterests = 'legitimate_interests';
}
