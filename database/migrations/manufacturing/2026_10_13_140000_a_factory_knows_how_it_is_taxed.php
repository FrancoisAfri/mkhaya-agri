<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-001.08 -- "currencies and tax settings".
 *
 * Per factory, like the currency, because a group with plants in two countries registers and
 * charges tax in each. Nullable throughout: a plant that has not been set up yet has no tax code,
 * and a NULL rate is "not configured" where 0.00 would claim the plant is zero-rated.
 *
 * The code mirrors the shape fin_items already uses (tax_code + tax_rate), so a cost posting can
 * carry one into finance without a translation step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfg_factories', function (Blueprint $table) {
            $table->string('tax_code', 16)->nullable()->after('currency');
            $table->decimal('tax_rate', 8, 4)->nullable()->after('tax_code');
            $table->string('tax_registration_number', 64)->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('mfg_factories', function (Blueprint $table) {
            $table->dropColumn(['tax_code', 'tax_rate', 'tax_registration_number']);
        });
    }
};
