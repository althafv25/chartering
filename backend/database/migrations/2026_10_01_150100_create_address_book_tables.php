<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3 — Address book: organisations separate from people. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('legal_name', 200);
            $table->string('normalized_name', 200)->index(); // duplicate detection
            $table->string('trading_name', 200)->nullable();
            $table->char('country', 2)->nullable()->index();
            $table->string('city', 100)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website', 200)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->char('default_currency', 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('credit_limit', 18, 2)->nullable();
            $table->string('status', 20)->default('active')->index(); // active|inactive|blocked
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('default_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index('legal_name');
        });

        Schema::create('company_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->timestamps();
            $table->unique(['company_id', 'role']);
            $table->index('role');
        });

        Schema::create('company_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 200);
            $table->string('normalized_alias', 200)->index();
            $table->string('reason', 20)->default('former_name'); // former_name|abbreviation|other
            $table->date('valid_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 75);
            $table->string('last_name', 75)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('email', 150)->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 150);
            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->string('iban', 40)->nullable();
            $table->string('swift_bic', 11)->nullable();
            $table->char('currency', 3)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['company_bank_accounts', 'contacts', 'company_aliases', 'company_roles', 'companies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
