<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair: `laytime_sof_events` and `laytime_exceptions` are defined in 2026_10_02_180000_create_laytime_tables, but that
 * file was extended after it had already been applied to some databases, so those databases recorded it as run without
 * the two child tables (the laytime screen's statement of facts and exceptions then fail). Applied migrations must never
 * be edited; this one adds the tables where they are missing and does nothing on databases that already have them.
 * The definitions are identical to the original migration. down() is intentionally empty: the original migration owns
 * (and drops) these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laytime_sof_events')) {
            Schema::create('laytime_sof_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laytime_calculation_id')->constrained()->cascadeOnDelete();
                $table->timestamp('event_at');
                $table->string('event_code', 50);
                $table->string('description', 255)->nullable();
                $table->string('source', 30)->default('manual');
                $table->timestamps();
                $table->index(['laytime_calculation_id', 'event_at']);
            });
        }

        if (! Schema::hasTable('laytime_exceptions')) {
            Schema::create('laytime_exceptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laytime_calculation_id')->constrained()->cascadeOnDelete();
                $table->timestamp('from_at');
                $table->timestamp('to_at');
                $table->string('exception_type', 50);
                $table->decimal('pct_counted', 7, 4)->default(0);
                $table->text('remarks')->nullable();
                $table->timestamps();
                $table->index(['laytime_calculation_id', 'from_at']);
            });
        }
    }

    public function down(): void
    {
        // Owned by 2026_10_02_180000_create_laytime_tables.
    }
};
