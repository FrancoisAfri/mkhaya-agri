<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many businesses a plan covers.
 *
 * Sits beside `websites_per_month` and `backlinks_per_month`, which is where the tenant-facing
 * side of Content Marketing keeps the same idea. Nullable rather than defaulted to a number, so
 * the service can tell "not priced yet" (→ one business, included with the module) apart from a
 * deliberate zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_modules') && ! Schema::hasColumn('billing_modules', 'entities_included')) {
            Schema::table('billing_modules', function (Blueprint $table) {
                $table->integer('entities_included')->nullable()->after('websites_per_month');
            });
        }

        // The accounting module ships covering one business; extra ones are an add-on.
        DB::table('billing_modules')->where('code', 'accounting')->whereNull('entities_included')
            ->update(['entities_included' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_modules') && Schema::hasColumn('billing_modules', 'entities_included')) {
            Schema::table('billing_modules', function (Blueprint $table) {
                $table->dropColumn('entities_included');
            });
        }
    }
};
