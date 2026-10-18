<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills companies.created_by (the account OWNER) for existing tenants provisioned
 * before it was recorded. The owner is taken as the EARLIEST user of the company — the
 * provisioner always creates the admin user first, so the lowest user id in a company
 * is its original account admin. Only the owner may manage subscription + billing.
 * Companies that already have created_by set, or have no users, are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'created_by')) {
            return;
        }

        $companies = DB::table('companies')
            ->whereNull('created_by')
            ->pluck('id');

        foreach ($companies as $companyId) {
            $ownerId = DB::table('users')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');

            if ($ownerId) {
                DB::table('companies')->where('id', $companyId)->update(['created_by' => $ownerId]);
            }
        }
    }

    public function down(): void
    {
        // Not reversible — we don't clear ownership.
    }
};
