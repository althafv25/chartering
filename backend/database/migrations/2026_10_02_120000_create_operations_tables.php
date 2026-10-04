<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — voyage lifecycle, port calls, milestones, off-hire, captain reports.
 * All datetimes are stored in UTC (G-06); local entry is converted by the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voyages', function (Blueprint $table) {
            $table->timestamp('commenced_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('commenced_at');
            $table->timestamp('finalized_at')->nullable()->after('completed_at');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('finalized_by');
            $table->string('status_reason', 1000)->nullable()->after('cancelled_at');
            $table->unsignedSmallInteger('reopened_count')->default(0)->after('status_reason');
            $table->timestamp('status_changed_at')->nullable()->after('reopened_count');
        });

        Schema::create('port_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voyage_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('port_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('offshore_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('agent_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('purpose', 20);
            $table->string('berth', 120)->nullable();
            foreach (['eta', 'etb', 'etd', 'ata', 'atb', 'atd'] as $col) {
                $table->timestamp($col)->nullable();
            }
            $table->string('status', 20)->default('planned');
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['voyage_id', 'sequence']);
            $table->index(['status', 'eta']);
        });

        Schema::create('captain_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('voyage_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('port_call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_type', 30);
            $table->timestamp('reported_at');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->decimal('speed_kn', 6, 2)->nullable();
            $table->unsignedSmallInteger('course_deg')->nullable();
            $table->decimal('distance_since_last_nm', 10, 2)->nullable();
            $table->decimal('distance_to_go_nm', 10, 2)->nullable();
            $table->unsignedTinyInteger('wind_force_bft')->nullable();
            $table->string('wind_direction', 10)->nullable();
            $table->string('sea_state', 30)->nullable();
            $table->string('weather_text', 255)->nullable();
            $table->decimal('main_engine_hours', 8, 2)->nullable();
            $table->decimal('aux_engine_hours', 8, 2)->nullable();
            $table->text('activity_text')->nullable();
            $table->decimal('delay_hours', 8, 2)->nullable();
            $table->string('delay_reason', 255)->nullable();
            $table->text('remarks')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vessel_id', 'reported_at']);
            $table->index(['voyage_id', 'reported_at']);
        });

        Schema::create('captain_report_fuel_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captain_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->decimal('rob_mt', 12, 3)->nullable();
            $table->decimal('consumed_mt', 12, 3)->default(0);
            $table->decimal('received_mt', 12, 3)->default(0);
            $table->timestamps();
            $table->unique(['captain_report_id', 'fuel_type_id']);
        });

        Schema::create('voyage_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voyage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('port_call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('milestone_type_id')->constrained()->restrictOnDelete();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('actual_at')->nullable();
            $table->string('source', 20)->default('manual');
            $table->foreignId('captain_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['voyage_id', 'actual_at']);
        });

        Schema::create('off_hire_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voyage_id')->constrained()->cascadeOnDelete();
            $table->timestamp('from_at');
            $table->timestamp('to_at')->nullable();
            $table->string('reason_code', 30);
            $table->string('description', 1000)->nullable();
            $table->decimal('hours', 10, 4)->nullable();
            $table->json('fuel_consumed')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['voyage_id', 'from_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('off_hire_events');
        Schema::dropIfExists('voyage_milestones');
        Schema::dropIfExists('captain_report_fuel_lines');
        Schema::dropIfExists('captain_reports');
        Schema::dropIfExists('port_calls');
        Schema::table('voyages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['commenced_at', 'completed_at', 'finalized_at', 'cancelled_at', 'status_reason', 'reopened_count', 'status_changed_at']);
        });
    }
};
