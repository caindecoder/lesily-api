<?php

namespace App\Companies\Enums;

/**
 * The seven identity-artefact kinds a platform admin may cite when verifying
 * an ownership-transfer case (UC-1-113; slice-11R spec §2). Artefact METADATA
 * rides the CompanyOwnershipVerificationRecorded event; prose notes never do.
 */
enum VerificationArtefactType: string
{
    case EmailConfirmation = 'email_confirmation';
    case IdDocument = 'id_document';
    case VideoCall = 'video_call';
    case DeathCertificate = 'death_certificate';
    case Poa = 'poa';
    case CourtOrder = 'court_order';
    case Other = 'other';
}
