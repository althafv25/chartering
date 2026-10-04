<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 8a — bunker stems (purchases). The ROB ledger is derived from verified captain reports (no table, see 05). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bunker_stems', function (Blueprint $table) {
            $table->id();
            $table->string('stem_number', 30)->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('voyage_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('port_call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('port_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->date('ordered_on');
            $table->decimal('ordered_mt', 12, 3);
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('delivered_mt', 12, 3)->nullable();
            $table->decimal('price_per_mt', 18, 4);
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8)->nullable();      // currency → base at delivery (snapshot)
            $table->string('fx_method', 20)->nullable();
            $table->decimal('total_amount', 18, 2)->nullable(); // delivered_mt × price (or ordered_mt while ordered)
            $table->decimal('base_amount', 18, 2)->nullable();
            $table->string('bdn_number', 60)->nullable();
            $table->string('invoice_reference', 60)->nullable();
            $table->string('status', 20)->default('ordered')->index(); // ordered|delivered|invoiced|cancelled
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vessel_id', 'delivered_at']);
            $table->index(['voyage_id', 'fuel_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bunker_stems');
    }
};
