<?php

use Spatie\EventSourcing\Projectionist;
use Illuminate\Database\Migrations\Migration;
use App\Consent\Projectors\ConsentRecordProjector;

// Consents recorded before consent_records existed are copied in by the table's
// own projector, replayed over the stored events. No DDL here.
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(Projectionist::class)->replay(collect([app(ConsentRecordProjector::class)]));
    }
};
