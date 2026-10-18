<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen company_identities.company_logo so the logo can be stored IN the database
 * (base64 JSON blob, the app's standard FileStorage pattern) instead of on the
 * filesystem — survives deploys / multi-server. varchar(255) was too small for image
 * data. Existing filesystem paths remain valid (the branding route serves both).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_identities', 'company_logo') && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_identities ALTER COLUMN company_logo TYPE text');
        }
    }

    public function down(): void
    {
        // Leave as text — narrowing could truncate stored logos.
    }
};
