<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 8b — Port DA (Disbursement Account). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('port_das', function (Blueprint $table) {
            $table->id();
            $table->string('da_number', 30)->unique();
            $table->foreignId('port_call_id')->constrained()->restrictOnDelete();
            $table->foreignId('voyage_id')->constrained()->restrictOnDelete();
            $table->foreignId('port_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->enum('da_type', ['proforma', 'final'])->default('proforma')->index();
            $table->foreignId('proforma_da_id')->nullable()->constrained('port_das')->nullOnDelete();
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->string('fx_method', 20)->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('draft')->index(); // draft|submitted|approved|settled
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['voyage_id', 'da_type']);
        });

        Schema::create('port_da_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('port_da_id')->constrained()->cascadeOnDelete();
            $table->foreignId('da_cost_category_id')->constrained()->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('estimated_amount', 18, 2)->nullable();
            $table->decimal('actual_amount', 18, 2)->nullable();
            $table->decimal('variance_amount', 18, 2)->nullable(); // actual - estimated
            $table->text('remarks')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();
            $table->index(['port_da_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_da_items');
        Schema::dropIfExists('port_das');
    }
};
