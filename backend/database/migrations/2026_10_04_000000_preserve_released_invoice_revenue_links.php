<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable();
            // Keep historical provenance while enforcing INV-02 for active links only.
            $table->unsignedBigInteger('active_voyage_revenue_id')->nullable()
                ->storedAs('CASE WHEN released_at IS NULL THEN voyage_revenue_id ELSE NULL END');
            $table->unique('active_voyage_revenue_id');
            $table->index('voyage_revenue_id');
        });
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropUnique(['voyage_revenue_id']));

        $inactive = DB::table('invoices')->where('status', 'cancelled')->orWhereNotNull('deleted_at')->select('id');
        DB::table('invoice_lines')->whereIn('invoice_id', $inactive)->whereNotNull('voyage_revenue_id')->update(['released_at' => now()]);
        DB::table('voyage_revenues')->where('status', 'invoiced')
            ->whereIn('id', DB::table('invoice_lines')->whereNotNull('released_at')->select('voyage_revenue_id'))
            ->whereNotIn('id', DB::table('invoice_lines')->whereNotNull('active_voyage_revenue_id')->select('active_voyage_revenue_id'))
            ->update(['status' => 'confirmed']);
    }

    public function down(): void
    {
        if (DB::table('invoice_lines')->whereNotNull('voyage_revenue_id')->groupBy('voyage_revenue_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore lifetime invoice uniqueness after revenue has been re-invoiced. Keep the migration to preserve billing history.');
        }
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unique('voyage_revenue_id');
            $table->dropUnique(['active_voyage_revenue_id']);
            $table->dropIndex(['voyage_revenue_id']);
            $table->dropColumn('active_voyage_revenue_id');
            $table->dropColumn('released_at');
        });
    }
};
