<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — enquiries, estimations/scenarios, offers/revisions, fixtures and
 * the minimal voyage shell needed for direct estimation → voyage conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        // BR-EST-03: commission is configurable per revenue category (default for new items).
        Schema::table('revenue_categories', function (Blueprint $table) {
            $table->boolean('is_commissionable')->default(false)->after('group');
        });

        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('enquiry_number', 30)->unique();
            $table->dateTime('received_at');
            $table->string('source', 20)->default('direct'); // direct|broker|tender
            $table->string('business_type', 30);              // voyage_charter|time_charter|offshore_charter|cargo_relet|service
            $table->foreignId('charterer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('broker_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('cargo_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cargo_description', 255)->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('quantity_unit', 10)->nullable();
            $table->decimal('quantity_tolerance_pct', 7, 4)->nullable();
            $table->foreignId('offshore_location_id')->nullable()->constrained()->nullOnDelete();
            $table->date('laycan_from')->nullable();
            $table->date('laycan_to')->nullable();
            $table->decimal('period_days', 10, 2)->nullable();
            $table->decimal('rate_idea', 18, 4)->nullable();
            $table->string('rate_basis', 20)->nullable(); // per_mt|per_day|per_hour|lump_sum
            $table->char('currency', 3)->nullable();
            $table->text('commission_terms')->nullable();
            $table->text('terms')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->string('lost_reason', 255)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['business_type', 'status']);
            $table->index('received_at');
        });

        Schema::create('enquiry_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('port_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('offshore_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('purpose', 20); // load|discharge|bunker|supply_base|offshore_ops|delivery|redelivery|other
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->unique(['enquiry_id', 'sequence']);
        });

        Schema::create('enquiry_vessels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->string('shortlist_status', 20)->default('candidate'); // candidate|selected|rejected
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->unique(['enquiry_id', 'vessel_id']);
        });

        Schema::create('estimations', function (Blueprint $table) {
            $table->id();
            $table->string('estimation_number', 30)->unique();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('estimation_type', 30); // voyage_charter|time_charter|offshore_day_rate|cargo_relet
            $table->string('title', 200);
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('status', 20)->default('draft')->index(); // draft|submitted|approved|rejected
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            $table->foreignId('cloned_from_id')->nullable()->constrained('estimations')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        // MySQL 5.7: base columns of stored generated columns cannot use ON DELETE CASCADE,
        // so parents (soft-deleted anyway) use RESTRICT.
        Schema::create('estimation_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimation_id')->constrained()->restrictOnDelete();
            $table->string('code', 4);
            $table->string('name', 120);
            $table->boolean('is_selected')->default(false);
            // MySQL has no partial unique index: NULL when not selected → at most one selected per estimation.
            $table->unsignedBigInteger('selected_key')->nullable()->storedAs('IF(is_selected, estimation_id, NULL)')->unique();
            $table->json('vessel_snapshot');           // particulars used (snapshot)
            $table->foreignId('consumption_profile_id')->nullable()->constrained('vessel_consumption_profiles')->nullOnDelete(); // trace only
            $table->json('inputs');                    // full calculation input snapshot
            $table->char('inputs_hash', 64)->nullable();
            $table->string('calc_status', 20)->default('not_calculated'); // not_calculated|calculated|incomplete|stale
            $table->json('calc_issues')->nullable();
            $table->string('calculation_version', 20)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('defaults_refreshed_at')->nullable();
            $table->foreignId('cloned_from_id')->nullable()->constrained('estimation_scenarios')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['estimation_id', 'code']);
        });

        Schema::create('scenario_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->unique()->constrained('estimation_scenarios')->cascadeOnDelete();
            $table->string('calculation_version', 20);
            $table->char('inputs_hash', 64);
            $table->decimal('sea_distance_nm', 12, 2);
            $table->decimal('eca_distance_nm', 12, 2);
            $table->decimal('sea_days', 12, 6);
            $table->decimal('eca_sea_days', 12, 6);
            $table->decimal('port_days', 12, 6);
            $table->decimal('total_days', 12, 6);
            $table->decimal('fuel_total_mt', 14, 3);
            foreach (['fuel_cost', 'port_costs', 'agency_costs', 'canal_costs', 'other_costs', 'operational_costs', 'tonnage_cost',
                'gross_revenue', 'total_commission', 'net_revenue', 'voyage_costs', 'total_costs', 'profit'] as $col) {
                $table->decimal($col, 18, 2);
            }
            $table->decimal('profit_margin_pct', 9, 4)->nullable();
            $table->decimal('profit_per_day', 18, 2)->nullable();
            $table->decimal('tce_per_day', 18, 2)->nullable();
            $table->decimal('breakeven_rate', 18, 4)->nullable();
            $table->string('breakeven_basis', 20)->nullable();
            $table->string('breakeven_item', 150)->nullable();
            $table->json('breakdown'); // fuel per type, revenue items, cost items, legs, calls
            $table->json('trace');
            $table->json('warnings')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('offer_number', 30)->unique();
            $table->foreignId('enquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('counterparty_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('status', 20)->default('open')->index(); // open|accepted|declined|withdrawn
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('offer_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('revision_no');
            $table->string('direction', 10); // outbound|inbound
            $table->string('status', 20)->default('draft')->index(); // draft|sent|received|superseded|accepted|rejected
            $table->foreignId('estimation_scenario_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('rate', 18, 4);
            $table->string('rate_basis', 20);
            $table->char('currency', 3);
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('quantity_unit', 10)->nullable();
            $table->date('laycan_from')->nullable();
            $table->date('laycan_to')->nullable();
            $table->decimal('period_days', 10, 2)->nullable();
            $table->json('ports');        // snapshot [{sequence, type, id, label, purpose}]
            $table->json('commissions');  // {address_pct, brokerage_pct, other_pct, broker_company_id}
            $table->text('terms')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['offer_id', 'revision_no']);
            // At most one accepted revision per offer.
            $table->unsignedBigInteger('accepted_key')->nullable()->storedAs("IF(status = 'accepted', offer_id, NULL)")->unique();
        });

        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('fixture_number', 30)->unique();
            $table->foreignId('offer_revision_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('estimation_scenario_id')->constrained()->restrictOnDelete();
            $table->foreignId('enquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('charterer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('owner_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('broker_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->date('fixture_date');
            $table->string('business_type', 30);
            $table->string('cargo_description', 255)->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('quantity_unit', 10)->nullable();
            $table->date('laycan_from')->nullable();
            $table->date('laycan_to')->nullable();
            $table->decimal('rate', 18, 4);
            $table->string('rate_basis', 20);
            $table->char('currency', 3);
            $table->json('ports');
            $table->json('commissions');
            $table->text('terms')->nullable();
            $table->json('recap_snapshot'); // revision + scenario inputs/results + vessel at fixture time
            $table->string('status', 20)->default('draft')->index(); // draft|submitted|approved|fixed|failed|cancelled (phase 5)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('voyages', function (Blueprint $table) {
            $table->id();
            $table->string('voyage_number', 30)->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('fixture_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('estimation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('estimation_scenario_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('conversion_type', 30); // fixture|direct_estimation
            $table->unsignedBigInteger('direct_key')->nullable()
                ->storedAs("IF(conversion_type = 'direct_estimation', estimation_scenario_id, NULL)")->unique();
            $table->string('direct_reason', 1000)->nullable();
            $table->string('operation_type', 20); // voyage|time_charter|offshore
            $table->foreignId('charterer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->char('currency', 3);
            $table->string('status', 20)->default('draft')->index();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('voyage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voyage_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // initial|milestone|final
            $table->string('name', 120);
            $table->unsignedBigInteger('single_key')->nullable()->storedAs("IF(type IN ('initial','final'), voyage_id, NULL)");
            $table->json('payload');
            $table->string('calculation_version', 20)->nullable();
            $table->char('inputs_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['single_key', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['voyage_snapshots', 'voyages', 'fixtures', 'offer_revisions', 'offers', 'scenario_results', 'estimation_scenarios', 'estimations', 'enquiry_vessels', 'enquiry_ports', 'enquiries'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('revenue_categories', fn (Blueprint $t) => $t->dropColumn('is_commissionable'));
    }
};
