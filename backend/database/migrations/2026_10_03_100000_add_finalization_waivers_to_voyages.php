<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — OP-04 finalization gates. `finalization_waivers` stores, per gate
 * (ledger, revenue, port_da, laytime), the reason and who/when it was waived —
 * null/absent means the gate was satisfied normally. Written only at finalize
 * time inside the same transaction as the status change (G-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voyages', function (Blueprint $table) {
            $table->json('finalization_waivers')->nullable()->after('finalized_by');
        });
    }

    public function down(): void
    {
        Schema::table('voyages', function (Blueprint $table) {
            $table->dropColumn('finalization_waivers');
        });
    }
};
