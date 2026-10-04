<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tax codes for invoice tax calculations
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('rate_pct', 7, 4); // e.g. 5.0000 = 5%
            $table->string('country_code', 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 30)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Voyage revenues (from activities, laytime, manual entries)
        Schema::create('voyage_revenues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voyage_id')->nullable()->constrained('voyages')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->restrictOnDelete();
            $table->foreignId('offshore_activity_id')->nullable()->constrained('offshore_activities')->restrictOnDelete();
            $table->foreignId('laytime_calculation_id')->nullable()->constrained('laytime_calculations')->restrictOnDelete();
            $table->foreignId('revenue_category_id')->constrained('revenue_categories')->restrictOnDelete();
            $table->string('description');
            $table->boolean('is_estimate')->default(false)->index();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->decimal('rate', 18, 4)->nullable();
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8);
            $table->decimal('amount', 18, 2);
            $table->decimal('base_amount', 18, 2)->index();
            $table->decimal('commission_pct_total', 7, 4)->default(0);
            $table->decimal('commission_amount', 18, 2)->default(0);
            $table->string('status', 30)->default('draft')->index(); // draft|confirmed|invoiced|cancelled
            $table->date('service_period_from')->nullable();
            $table->date('service_period_to')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['voyage_id', 'status']);
            $table->index(['contract_id', 'status']);
        });

        // Voyage expenses (from port DA, bunkers, payables, manual)
        Schema::create('voyage_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voyage_id')->nullable()->constrained('voyages')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->nullableMorphs('source'); // port_da, bunker_stem, payable, offshore_activity
            $table->string('description');
            $table->boolean('is_estimate')->default(false)->index();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->decimal('rate', 18, 4)->nullable();
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8);
            $table->decimal('amount', 18, 2);
            $table->decimal('base_amount', 18, 2)->index();
            $table->foreignId('supplier_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('status', 30)->default('draft')->index(); // draft|confirmed|approved|paid|cancelled
            $table->dateTime('incurred_at')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['voyage_id', 'status']);
            $table->index(['contract_id', 'status']);
        });

        // Invoices (accounts receivable)
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->unique()->nullable(); // Assigned at issue
            $table->string('invoice_type', 30)->index(); // freight|hire|offshore_service|demurrage|other|credit_note
            $table->foreignId('customer_company_id')->constrained('companies')->restrictOnDelete();
            $table->json('billing_snapshot'); // name, address, tax_no at invoice time
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->restrictOnDelete();
            $table->foreignId('voyage_id')->nullable()->constrained('voyages')->restrictOnDelete();
            $table->date('issue_date')->index();
            $table->date('due_date')->index();
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->decimal('base_total', 18, 2)->index();
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->decimal('balance', 18, 2)->storedAs('total - amount_paid');
            $table->string('status', 30)->default('draft')->index(); // draft|submitted|approved|issued|partially_paid|paid|overdue|cancelled
            $table->string('cancelled_reason')->nullable();
            $table->foreignId('credit_note_for_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('pdf_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['customer_company_id', 'status']);
            $table->index(['status', 'due_date']); // For overdue job
        });

        // Invoice lines
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('voyage_revenue_id')->nullable()->unique()->constrained('voyage_revenues')->restrictOnDelete();
            $table->string('description');
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('rate', 18, 4);
            $table->decimal('amount', 18, 2);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate_pct', 7, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();

            $table->unique(['invoice_id', 'sequence']);
        });

        // Payables (supplier invoices received)
        Schema::create('payables', function (Blueprint $table) {
            $table->id();
            $table->string('payable_number', 30)->unique();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('supplier_invoice_ref');
            $table->foreignId('voyage_id')->nullable()->constrained('voyages')->restrictOnDelete();
            $table->date('issue_date')->index();
            $table->date('due_date')->index();
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->decimal('base_total', 18, 2)->index();
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->decimal('balance', 18, 2)->storedAs('total - amount_paid');
            $table->string('status', 30)->default('draft')->index(); // draft|approved|partially_paid|paid|cancelled
            $table->text('remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['supplier_company_id', 'supplier_invoice_ref']);
            $table->index(['supplier_company_id', 'status']);
            $table->index(['status', 'due_date']);
        });

        // Payments (received from customers or paid to suppliers)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 30)->unique();
            $table->string('direction', 10)->index(); // received|paid
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete(); // customer or supplier
            $table->date('payment_date')->index();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8);
            $table->decimal('base_amount', 18, 2)->index();
            $table->decimal('unallocated_amount', 18, 2)->default(0); // Calculated by service
            $table->string('bank_account_ref')->nullable();
            $table->string('bank_reference')->nullable();
            $table->string('method', 50)->nullable(); // wire|check|credit_card|cash
            $table->text('remarks')->nullable();
            $table->string('status', 30)->default('recorded')->index(); // recorded|reversed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['company_id', 'direction']);
            $table->index(['bank_reference', 'company_id']); // Duplicate detection
        });

        // Payment allocations (to invoices or payables)
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payable_id')->nullable()->constrained('payables')->restrictOnDelete();
            $table->decimal('allocated_amount', 18, 2); // In payment currency
            $table->decimal('invoice_ccy_amount', 18, 2)->nullable(); // Converted to invoice/payable currency
            $table->decimal('fx_difference_base', 18, 2)->nullable()->comment('Realized FX gain/loss in base currency');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One allocation per payment-invoice pair
            $table->unique(['payment_id', 'invoice_id']);
            $table->unique(['payment_id', 'payable_id']);
            $table->index('invoice_id');
            $table->index('payable_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payables');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('voyage_expenses');
        Schema::dropIfExists('voyage_revenues');
        Schema::dropIfExists('tax_codes');
    }
};
