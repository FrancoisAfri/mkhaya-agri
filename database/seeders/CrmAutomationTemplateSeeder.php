<?php

namespace Database\Seeders;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Materialises the built-in CRM automation templates (CrmWorkflow::TEMPLATES)
 * as real workflows. Seeded **paused** (is_active = false) so an admin reviews
 * and activates them — they never start firing on their own. Idempotent: an
 * existing workflow with the same name is left untouched (preserves admin edits
 * and activation across re-runs).
 */
class CrmAutomationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $ownerId = User::where('is_master_admin', true)->value('id')
            ?? User::orderBy('id')->value('id');

        foreach (CrmWorkflow::TEMPLATES as $template) {
            CrmWorkflow::firstOrCreate(
                ['name' => $template['name']],
                [
                    'uuid'           => (string) Str::uuid(),
                    'description'    => $template['description'] ?? null,
                    'trigger'        => $template['trigger'],
                    'trigger_config' => $template['trigger_config'] ?? [],
                    'match_type'     => 'all',
                    'conditions'     => [],
                    'actions'        => [],
                    'steps'          => $template['steps'] ?? [],
                    'is_active'      => false, // paused — admin activates after review
                    'run_order'      => 0,
                    'created_by'     => $ownerId,
                ]
            );
        }

        $this->command?->info('Seeded ' . count(CrmWorkflow::TEMPLATES) . ' CRM automation templates (paused).');
    }
}
