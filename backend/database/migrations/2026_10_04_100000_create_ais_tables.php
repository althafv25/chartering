<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — AIS (docs/09, 05 §9). ais_positions is append-only and de-duplicated by
 * (vessel, observed_at, provider); vessel_latest_positions keeps one row per vessel for the fleet map.
 * Positions are advisory and never merged into captain reports or milestones (AIS-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ais_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained()->cascadeOnDelete();
            $table->string('imo', 10)->nullable();
            $table->string('mmsi', 15)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->decimal('sog_kn', 5, 2)->nullable();
            $table->decimal('cog_deg', 5, 2)->nullable();
            $table->unsignedSmallInteger('heading_deg')->nullable();
            $table->string('nav_status', 40)->nullable();
            $table->string('destination', 100)->nullable();
            $table->dateTime('eta_reported')->nullable();
            $table->decimal('draught_m', 5, 2)->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('received_at');
            $table->string('provider', 30);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // manual entries only
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['vessel_id', 'observed_at', 'provider']);
            $table->index(['vessel_id', 'observed_at']);
        });

        Schema::create('vessel_latest_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('ais_position_id')->constrained('ais_positions')->cascadeOnDelete();
            $table->timestamp('observed_at');
            $table->boolean('is_stale')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_latest_positions');
        Schema::dropIfExists('ais_positions');
    }
};
