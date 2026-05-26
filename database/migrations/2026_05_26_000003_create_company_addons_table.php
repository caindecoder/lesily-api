<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('addon', 32);
            $table->timestamp('subscribed_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->unique(['company_id', 'addon']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_addons');
    }
};
