<?php

namespace Database\Seeders;

use App\Core\Module\Models\Modules;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the CRM's default sales pipeline + stages, and normalises CRM ribbon
 * visibility (every CRM ribbon at access_level 1 so anyone with CRM module
 * access sees them all). Module / category / ribbon registration itself lives
 * canonically in ModuleSeeders. Idempotent.
 */
class CrmModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->normaliseRibbonVisibility();

        $pipeline = CrmPipeline::firstOrCreate(
            ['name' => 'Sales Pipeline'],
            ['uuid' => (string) Str::uuid(), 'description' => 'Default sales pipeline', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]
        );

        $stages = [
            ['New',         10, false, false, 'blue'],
            ['Qualified',   25, false, false, 'indigo'],
            ['Proposal',    50, false, false, 'purple'],
            ['Negotiation', 75, false, false, 'orange'],
            ['Won',        100, true,  false, 'green'],
            ['Lost',         0, false, true,  'red'],
        ];

        foreach ($stages as $i => [$name, $prob, $won, $lost, $color]) {
            CrmStage::firstOrCreate(
                ['pipeline_id' => $pipeline->id, 'name' => $name],
                ['sort_order' => $i + 1, 'probability' => $prob, 'is_won' => $won, 'is_lost' => $lost, 'color' => $color]
            );
        }
    }

    /**
     * The navigation shows a ribbon when the user's CRM module access_level is
     * >= the ribbon's access_level. Older CRM ribbons were seeded at level 1 and
     * newer ones at 5, so users below level 5 only saw the old ribbons. Level
     * every CRM ribbon to 1 so anyone with CRM module access sees all of them.
     */
    private function normaliseRibbonVisibility(): void
    {
        $module = Modules::where('code_name', 'crm')->first();
        if (! $module) {
            return;
        }

        DB::table('security_module_ribbons')->where('module_id', $module->id)->update(['access_level' => 1]);
    }
}
