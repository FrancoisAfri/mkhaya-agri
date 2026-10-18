<?php

use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the MFG-007 floor-supervision permission, grants it to the platform admin roles and
 * re-syncs the ribbons. Gives every company already set up for manufacturing the standard downtime
 * reason codes, so the first stoppage can be recorded against a code. Idempotent.
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
        // Permissions and reason codes stay: roles may hold them and stoppages may cite them.
    }
};
