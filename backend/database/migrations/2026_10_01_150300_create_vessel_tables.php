<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3 — vessel master, consumption profiles, status history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique(); // short code used in voyage numbers
            $table->string('name', 150)->index();
            $table->char('imo_number', 7)->nullable()->unique();
            $table->char('mmsi', 9)->nullable()->unique();
            $table->string('call_sign', 20)->nullable();
            $table->string('official_number', 30)->nullable();
            $table->foreignId('vessel_type_id')->constrained()->restrictOnDelete();
            $table->string('subtype', 60)->nullable();
            $table->char('flag_country', 2)->nullable();
            $table->string('port_of_registry', 100)->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->string('builder', 150)->nullable();
            $table->string('class_society', 100)->nullable();
            $table->string('class_notation', 255)->nullable();
            $table->string('ownership_type', 20)->default('owned'); // owned|managed|chartered_in|third_party
            foreach (['owner', 'manager', 'commercial_manager', 'technical_manager'] as $party) {
                $table->foreignId("{$party}_company_id")->nullable()->constrained('companies')->nullOnDelete();
            }
            // Dimensions (m)
            foreach (['loa_m', 'lbp_m', 'beam_m', 'depth_m', 'summer_draft_m', 'air_draft_m'] as $col) {
                $table->decimal($col, 8, 3)->nullable();
            }
            // Tonnage
            $table->decimal('dwt_mt', 12, 3)->nullable();
            $table->decimal('gt', 12, 2)->nullable();
            $table->decimal('nt', 12, 2)->nullable();
            // Machinery
            $table->string('main_engine', 255)->nullable();
            $table->decimal('main_engine_power_kw', 10, 2)->nullable();
            $table->string('aux_engines', 255)->nullable();
            $table->decimal('aux_engine_power_kw', 10, 2)->nullable();
            $table->string('propulsion', 100)->nullable();
            // Speeds (kn)
            $table->decimal('service_speed_kn', 5, 2)->nullable();
            $table->decimal('max_speed_kn', 5, 2)->nullable();
            $table->decimal('eco_speed_kn', 5, 2)->nullable();
            // Offshore / capacity
            $table->decimal('deck_area_m2', 10, 2)->nullable();
            $table->decimal('deck_strength_t_m2', 6, 2)->nullable();
            $table->decimal('bollard_pull_t', 8, 2)->nullable();
            $table->string('dp_class', 5)->nullable(); // DP0..DP3
            $table->decimal('crane_swl_t', 8, 2)->nullable();
            $table->unsignedSmallInteger('crew_capacity')->nullable();
            $table->unsignedSmallInteger('passenger_capacity')->nullable();
            $table->json('custom_attributes')->nullable(); // validated against vessel_types.attribute_schema
            // Current status (denormalised from vessel_status_history for fast boards/filters)
            $table->string('commercial_status', 30)->nullable()->index();
            $table->string('operational_status', 30)->nullable()->index();
            $table->string('crew_management_vessel_id', 40)->nullable(); // integration mapping (D-008)
            $table->string('status', 20)->default('active')->index(); // active|inactive|sold|scrapped
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vessel_name_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->date('valid_to');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vessel_consumption_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80); // e.g. "Design", "CP 2026"
            $table->string('source', 20)->default('design'); // design|charter_party|observed
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('remarks', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vessel_id', 'name', 'effective_from']);
        });

        Schema::create('vessel_consumption_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('vessel_consumption_profiles')->cascadeOnDelete();
            $table->string('mode', 30);
            $table->decimal('speed_kn', 5, 2)->default(0); // 0 for non-speed modes (port, standby, DP)
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->decimal('consumption_mt_per_day', 10, 3);
            $table->timestamps();
            $table->unique(['profile_id', 'mode', 'speed_kn', 'fuel_type_id'], 'consumption_rate_unique');
        });

        Schema::create('vessel_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained()->cascadeOnDelete();
            $table->string('track', 20); // commercial|operational
            $table->string('status', 30);
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->foreignId('port_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offshore_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_text', 150)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('reason', 150)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vessel_id', 'track', 'effective_from'], 'vsh_vessel_track_from');
            $table->index(['track', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['vessel_status_history', 'vessel_consumption_rates', 'vessel_consumption_profiles', 'vessel_name_histories', 'vessels'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
