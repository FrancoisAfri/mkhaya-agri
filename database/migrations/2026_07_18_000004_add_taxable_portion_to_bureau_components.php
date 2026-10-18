<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a bureau earnings component be only PARTIALLY taxable — chiefly a travel
 * allowance, where only 80% is subject to PAYE (s8(1)). Default 1.0000 = fully
 * taxable, so existing components are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureau_components', function (Blueprint $table) {
            if (! Schema::hasColumn('bureau_components', 'taxable_portion')) {
                $table->decimal('taxable_portion', 5, 4)->default(1)->after('taxable');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bureau_components', function (Blueprint $table) {
            $table->dropColumn('taxable_portion');
        });
    }
};
