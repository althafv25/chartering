<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3 — ports, offshore locations, agents, cached distances. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->index();
            $table->char('unlocode', 5)->nullable()->unique();
            $table->char('country', 2)->index();
            $table->string('region', 60)->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->decimal('max_draft_m', 6, 2)->nullable();
            $table->decimal('max_loa_m', 7, 2)->nullable();
            $table->decimal('max_beam_m', 6, 2)->nullable();
            $table->text('restrictions')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('port_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('port_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('remarks', 255)->nullable();
            $table->timestamps();
            $table->unique(['port_id', 'company_id']);
        });

        Schema::create('offshore_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('field_name', 120)->nullable();
            $table->string('block', 60)->nullable();
            $table->foreignId('operator_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('nearest_port_id')->nullable()->constrained('ports')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('water_depth_m', 8, 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // A point is a port or an offshore location (point_type: port|location).
        Schema::create('port_distances', function (Blueprint $table) {
            $table->id();
            $table->string('from_type', 10);
            $table->unsignedBigInteger('from_id');
            $table->string('to_type', 10);
            $table->unsignedBigInteger('to_id');
            $table->string('route_key', 64)->default(''); // '' = default route; e.g. "via:suez"
            $table->decimal('distance_nm', 10, 2);
            $table->decimal('eca_distance_nm', 10, 2)->default(0);
            $table->string('provider', 50)->default('manual');
            $table->string('notes', 255)->nullable();
            $table->timestamp('calculated_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['from_type', 'from_id', 'to_type', 'to_id', 'route_key', 'provider'], 'distance_unique');
            $table->index(['to_type', 'to_id']);
        });
    }

    public function down(): void
    {
        foreach (['port_distances', 'offshore_locations', 'port_agents', 'ports'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
