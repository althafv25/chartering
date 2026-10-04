<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 7 — offshore projects and activities (hours, contract-rate snapshot, revenue). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offshore_projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('client_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('offshore_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('field_name', 120)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('planned')->index();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('offshore_activities', function (Blueprint $table) {
            $table->id();
            $table->string('activity_number', 30)->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('voyage_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('offshore_project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('client_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('offshore_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('offshore_activity_type_id')->constrained()->restrictOnDelete();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('description', 2000)->nullable();
            $table->decimal('billable_hours', 10, 4)->default(0);
            $table->decimal('non_billable_hours', 10, 4)->default(0);
            $table->decimal('standby_hours', 10, 4)->default(0);
            $table->char('currency', 3)->nullable();
            $table->unsignedInteger('contract_version_no')->nullable();
            $table->json('rate_snapshot')->nullable(); // calculation lines incl. contract_rate_id / version / unit / quantity
            $table->decimal('revenue_amount', 18, 2)->nullable();
            $table->string('calculation_basis', 60)->nullable(); // settings used, e.g. "hourly|standby_rate"
            $table->json('warnings')->nullable();
            $table->json('fuel_used')->nullable(); // [{fuel_type_id, mt}] — recorded only (BR-OA-04)
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vessel_id', 'start_at']);
            $table->index(['contract_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offshore_activities');
        Schema::dropIfExists('offshore_projects');
    }
};
