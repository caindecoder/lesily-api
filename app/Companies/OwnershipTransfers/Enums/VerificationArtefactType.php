<?php

namespace App\Companies\OwnershipTransfers\Enums;

/**
 * The kinds of identity evidence a platform admin can cite when verifying a
 * transfer (ERD-1-05 §3, OQ #3 RESOLVED s149). Metadata only — the system
 * records the structured reference trail, never the file bytes (OQ-1-045 / D4).
 *
 * The last three serve the §10b owner-death succession path. They were taken at
 * build rather than deferred because that flow is precisely where artefact
 * provenance is load-bearing, and routing a death certificate through `Other`
 * would erase the distinction the artefacts exist to record.
 */
enum VerificationArtefactType: string
{
    case EmailConfirmation = 'email_confirmation';
    case IdDocument = 'id_document';
    case VideoCall = 'video_call';
    case DeathCertificate = 'death_certificate';
    case PowerOfAttorney = 'poa';
    case CourtOrder = 'court_order';
    case Other = 'other';

    /**
     * The wire values, for the Command's `Rule::in` — one source of truth so the
     * accepted set cannot drift from this enum.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
