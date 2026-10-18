<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provisioning task can name the actual asset that satisfied it.
 *
 * "Laptop — completed" was free text: it recorded that somebody said a laptop was issued, not which
 * one. So the Assets module never knew the machine was out, the person's custody record was empty,
 * and at offboarding there was nothing to hand back — the exit clearance had to be assembled from
 * memory.
 *
 * Nullable on purpose. Plenty of provisioning is not an asset at all — an email account, a payroll
 * profile, building access — and forcing those to point at the register would be worse than leaving
 * them as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_provisioning_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('onboarding_provisioning_tasks', 'asset_id')) {
                $table->unsignedBigInteger('asset_id')->nullable()->index();
                // The assignment this task created, so completing it once and re-completing it does
                // not issue the same machine twice.
                $table->unsignedBigInteger('asset_assignment_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_provisioning_tasks', function (Blueprint $table) {
            foreach (['asset_id', 'asset_assignment_id'] as $column) {
                if (Schema::hasColumn('onboarding_provisioning_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
