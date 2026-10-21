<?php

namespace App\Companies\Memberships\Enums;

enum EmergencyContactRelationship: string
{
    case Spouse = 'spouse';
    case Parent = 'parent';
    case Sibling = 'sibling';
    case BusinessPartner = 'business_partner';
    case Lawyer = 'lawyer';
    case Other = 'other';
}
