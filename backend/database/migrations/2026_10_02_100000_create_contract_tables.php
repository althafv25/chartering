<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — fixture lifecycle columns, contracts with versioned rates/clauses,
 * amendments, and the voyage → contract link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('decided_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('decision_comment', 1000)->nullable()->after('decided_at');
            $table->text('remarks')->nullable()->after('terms');
            $table->unsignedInteger('lock_version')->default(0)->after('decision_comment');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number', 30)->unique();
            $table->string('contract_type', 30); // voyage_charter|time_charter|bareboat|offshore_charter|service|other
            $table->string('title', 200);
            $table->foreignId('fixture_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('vessel_id')->nullable()->constrained()->restrictOnDelete(); // BR-CT-01: one vessel (null for service contracts)
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable()->index();
            $table->text('extension_options')->nullable();
            $table->char('currency', 3);
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('payment_terms_text', 500)->nullable();
            $table->json('commissions');
            $table->text('terms')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedSmallInteger('current_version')->default(1);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable(); // completed / expired / cancelled
            $table->string('close_reason', 500)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['customer_company_id', 'status']);
            $table->index(['vessel_id', 'status']);
        });

        // One row per contract version: v1 = as approved; v2+ = each approved amendment.
        Schema::create('contract_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->date('effective_from');
            $table->unsignedBigInteger('amendment_id')->nullable();
            $table->json('header_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['contract_id', 'version_no']);
        });

        Schema::create('contract_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->string('rate_type', 30);
            $table->foreignId('offshore_activity_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 200)->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->string('unit', 20); // per_day|per_hour|per_mt|lump_sum
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['contract_id', 'version_no', 'rate_type']);
        });

        Schema::create('contract_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->unsignedSmallInteger('sequence');
            $table->string('clause_ref', 30)->nullable();
            $table->string('title', 200);
            $table->text('body');
            $table->timestamps();
            $table->index(['contract_id', 'version_no']);
        });

        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('amendment_no');
            $table->date('effective_date');
            $table->string('summary', 500);
            $table->json('proposal');           // {header?:{...}, rates?:[...], clauses?:[...]}
            $table->json('changes')->nullable(); // before/after, computed at approval
            $table->string('status', 20)->default('draft')->index(); // draft|submitted|approved|rejected|withdrawn
            $table->unsignedSmallInteger('resulting_version')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['contract_id', 'amendment_no']);
        });

        Schema::table('voyages', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('fixture_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voyages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });
        foreach (['contract_amendments', 'contract_clauses', 'contract_rates', 'contract_versions', 'contracts'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('fixtures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['submitted_at', 'decided_at', 'decision_comment', 'remarks', 'lock_version']);
        });
    }
};
