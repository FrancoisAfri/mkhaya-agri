<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-201: the four-eyes constraints stay, with one exception the database can see -- a request
 * decided by its own requester must name the emergency override that allowed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['wms_stock_adjustments', 'wms_revaluations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('override_id')->nullable()->constrained('wms_overrides')->nullOnDelete());
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wms_stock_adjustments DROP CONSTRAINT wms_adj_four_eyes');
            DB::statement("ALTER TABLE wms_stock_adjustments ADD CONSTRAINT wms_adj_four_eyes CHECK (decided_by IS NULL OR requested_by IS NULL OR decided_by <> requested_by OR status = 'CANCELLED' OR override_id IS NOT NULL)");
            DB::statement('ALTER TABLE wms_revaluations DROP CONSTRAINT wms_reval_four_eyes');
            DB::statement('ALTER TABLE wms_revaluations ADD CONSTRAINT wms_reval_four_eyes CHECK (decided_by IS NULL OR requested_by IS NULL OR decided_by <> requested_by OR override_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wms_stock_adjustments DROP CONSTRAINT wms_adj_four_eyes');
            DB::statement("ALTER TABLE wms_stock_adjustments ADD CONSTRAINT wms_adj_four_eyes CHECK (decided_by IS NULL OR requested_by IS NULL OR decided_by <> requested_by OR status = 'CANCELLED')");
            DB::statement('ALTER TABLE wms_revaluations DROP CONSTRAINT wms_reval_four_eyes');
            DB::statement('ALTER TABLE wms_revaluations ADD CONSTRAINT wms_reval_four_eyes CHECK (decided_by IS NULL OR requested_by IS NULL OR decided_by <> requested_by)');
        }
        foreach (['wms_stock_adjustments', 'wms_revaluations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('override_id'));
        }
    }
};
