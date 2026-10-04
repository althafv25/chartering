<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — currencies, exchange rates and simple reference (lookup) tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name', 60);
            $table->string('symbol', 8)->nullable();
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        // 1 unit of base_currency = rate units of quote_currency, valid from rate_date.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->string('source', 50)->default('manual');
            $table->string('remarks', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('base_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('quote_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['rate_date', 'base_currency', 'quote_currency', 'source'], 'fx_unique_day_pair_source');
            $table->index(['base_currency', 'quote_currency', 'rate_date'], 'fx_pair_date');
        });

        $reference = function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
        };

        Schema::create('vessel_types', function (Blueprint $table) use ($reference) {
            $reference($table);
            $table->string('category', 30)->default('offshore'); // offshore|tanker|bulk|general|other
            // Type-specific configurable fields: [{key,label,data_type,unit,options?}]
            $table->json('attribute_schema')->nullable();
            $table->timestamps();
        });

        Schema::create('fuel_types', function (Blueprint $table) use ($reference) {
            $reference($table);
            $table->string('category', 20); // HFO|VLSFO|ULSFO|LSMGO|MGO|MDO|LNG|METHANOL|OTHER
            $table->boolean('is_eca_compliant')->default(false);
            $table->timestamps();
        });

        Schema::create('cargo_types', function (Blueprint $table) use ($reference) {
            $reference($table);
            $table->string('unit', 10)->default('mt'); // mt|m3|units|bbl
            $table->timestamps();
        });

        Schema::create('offshore_activity_types', function (Blueprint $table) use ($reference) {
            $reference($table);
            $table->boolean('is_billable_default')->default(true);
            $table->timestamps();
        });

        Schema::create('milestone_types', function (Blueprint $table) use ($reference) {
            $reference($table);
            $table->string('applies_to', 10)->default('both'); // voyage|offshore|both
            $table->boolean('is_laytime_relevant')->default(false);
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) use ($reference) {
            $reference($table);
            $table->string('group', 30); // bunker|port|agency|canal|brokerage|commission|operational|mobilization|demobilization|supplier|other
            $table->timestamps();
        });

        Schema::create('revenue_categories', function (Blueprint $table) use ($reference) {
            $reference($table);
            $table->string('group', 30); // freight|hire|offshore_service|demurrage|other
            $table->timestamps();
        });

        Schema::create('da_cost_categories', function (Blueprint $table) use ($reference) {
            $reference($table);
            $table->foreignId('expense_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['da_cost_categories', 'revenue_categories', 'expense_categories', 'milestone_types', 'offshore_activity_types', 'cargo_types', 'fuel_types', 'vessel_types', 'exchange_rates', 'currencies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
