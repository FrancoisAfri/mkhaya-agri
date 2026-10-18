<?php

use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the MFG-009 quality permissions, grants them to the platform admin roles and re-syncs
 * the module's ribbons (the new Quality hub). Also gives every company that already numbers
 * manufacturing documents the new NCR sequence -- without it the first failed inspection would
 * stop on "no active number sequence". Idempotent throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ManufacturingModuleSeeder)->run();

        $installer = app(ManufacturingReferenceData::class);
        foreach (DB::table('mfg_number_sequences')->whereNotNull('company_id')->distinct()->pluck('company_id') as $companyId) {
            $installer->installFor((int) $companyId);
        }
    }

    public function down(): void
    {
        // Permissions and sequences stay: roles may hold them and numbers may have been issued.
    }
};
