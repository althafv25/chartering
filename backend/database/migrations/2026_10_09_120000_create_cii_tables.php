<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — Carbon Intensity Indicator (CII, MARPOL Annex VI).
 * A formula set holds the versioned IMO parameters (reference lines, reduction factors,
 * rating boundaries, emission factors) as JSON so Marine Operations can confirm/replace
 * them without a schema change (docs/CII-REQUIREMENTS.md). A vessel-year is the attained
 * CII for one vessel in one reporting year, computed against one formula set and snapshot
 * at calculation time — recalculating never silently changes a verified record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cii_formula_sets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->json('parameters'); // {reference_lines, reduction_factors, rating_boundaries, emission_factors}
            $table->date('effective_date');
            $table->date('expiration_date')->nullable();
            $table->string('status', 20)->default('draft')->index(); // draft|verified|retired
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cii_vessel_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('reporting_year');
            $table->foreignId('formula_set_id')->constrained('cii_formula_sets')->restrictOnDelete();
            $table->string('capacity_metric', 3); // DWT|GT, resolved from the vessel type at calculation
            $table->decimal('capacity_value', 12, 3); // the DWT/GT value used, snapshotted
            $table->json('fuel_consumption'); // {HFO: "120.500", MGO: "30.000", ...} metric tonnes per fuel code
            $table->decimal('distance_sailed_nm', 12, 2);
            $table->decimal('attained_cii', 14, 6)->nullable();
            $table->decimal('required_cii', 14, 6)->nullable();
            $table->string('rating', 1)->nullable(); // A|B|C|D|E
            $table->string('source', 20)->default('calculated'); // calculated|manual
            $table->string('scope_status', 20)->default('in_scope'); // in_scope|exempt
            $table->string('status', 20)->default('draft')->index(); // draft|calculated|verified
            $table->json('calculation_trace')->nullable();
            $table->string('calculation_version', 10)->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['vessel_id', 'reporting_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cii_vessel_years');
        Schema::dropIfExists('cii_formula_sets');
    }
};
