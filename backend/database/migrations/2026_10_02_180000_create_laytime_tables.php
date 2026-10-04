<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 8c — Laytime calculations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laytime_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('port_call_id')->constrained()->restrictOnDelete();
            $table->foreignId('voyage_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('calculation_type', ['load', 'discharge', 'reversible'])->index();
            // Allowed laytime inputs
            $table->decimal('fixed_hours', 12, 4)->nullable();
            $table->decimal('cargo_quantity', 14, 3)->nullable();
            $table->decimal('rate_per_day', 12, 4)->nullable();
            $table->string('rate_unit', 20)->nullable(); // MT, M3, etc
            // Terms
            $table->string('terms_code', 30)->nullable(); // SHINC|SHEX|SSHEX|FHEX|custom
            $table->json('terms_definition')->nullable(); // full BR-LT parameters
            // Commencement
            $table->timestamp('nor_tendered_at')->nullable();
            $table->timestamp('nor_accepted_at')->nullable();
            $table->decimal('notice_time_hours', 10, 4)->nullable();
            $table->timestamp('laytime_commenced_at')->nullable();
            $table->timestamp('laytime_completed_at')->nullable();
            // Rates
            $table->decimal('demurrage_rate_per_day', 18, 4)->nullable();
            $table->decimal('despatch_rate_per_day', 18, 4)->nullable();
            $table->char('currency', 3)->nullable();
            // Rules
            $table->string('once_on_demurrage_rule', 30)->default('always_on_demurrage'); // always_on_demurrage|exceptions_apply
            // Calculated results
            $table->decimal('allowed_hours', 12, 4)->nullable();
            $table->decimal('used_hours', 12, 4)->nullable();
            $table->decimal('difference_hours', 12, 4)->nullable(); // allowed - used
            $table->decimal('demurrage_amount', 18, 2)->nullable();
            $table->decimal('despatch_amount', 18, 2)->nullable();
            $table->string('calculation_version', 20)->nullable();
            $table->json('trace')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->string('status', 20)->default('draft')->index(); // draft|submitted|agreed|disputed
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('agreed_at')->nullable();
            $table->foreignId('agreed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['voyage_id', 'calculation_type']);
        });

        Schema::create('laytime_sof_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laytime_calculation_id')->constrained()->cascadeOnDelete();
            $table->timestamp('event_at');
            $table->string('event_code', 50); // NOR_TENDERED|NOR_ACCEPTED|COMMENCED|HOSES_CONNECTED|OPERATIONS_COMMENCED|OPERATIONS_COMPLETED|HOSES_DISCONNECTED|ALL_FAST|COMPLETED
            $table->string('description', 255)->nullable();
            $table->string('source', 30)->default('manual'); // manual|port_call|captain_report
            $table->timestamps();
            $table->index(['laytime_calculation_id', 'event_at']);
        });

        Schema::create('laytime_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laytime_calculation_id')->constrained()->cascadeOnDelete();
            $table->timestamp('from_at');
            $table->timestamp('to_at');
            $table->string('exception_type', 50); // weather|holiday|weekend|shifting|breakdown_owner|breakdown_charterer|strike|waiting_berth|other
            $table->decimal('pct_counted', 7, 4)->default(0); // 0 = excluded, 50 = half time, 100 = full
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['laytime_calculation_id', 'from_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laytime_exceptions');
        Schema::dropIfExists('laytime_sof_events');
        Schema::dropIfExists('laytime_calculations');
    }
};
