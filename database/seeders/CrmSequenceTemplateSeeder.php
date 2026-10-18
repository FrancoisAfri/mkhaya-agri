<?php

namespace Database\Seeders;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmSequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Materialises the built-in sequence templates (CrmSequence::TEMPLATES) as real
 * cadences, seeded **inactive** so an admin reviews and activates them.
 * Idempotent: an existing sequence with the same name is left untouched.
 */
class CrmSequenceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $ownerId = User::where('is_master_admin', true)->value('id') ?? User::orderBy('id')->value('id');

        foreach (CrmSequence::TEMPLATES as $template) {
            CrmSequence::firstOrCreate(
                ['name' => $template['name']],
                [
                    'uuid'          => (string) Str::uuid(),
                    'goal'          => $template['description'] ?? null,
                    'goal_event'    => $template['goal_event'] ?? null,
                    'steps'         => $template['steps'] ?? [],
                    'active'        => false, // paused — admin activates after review
                    'stop_on_reply' => true,
                    'track_opens'   => true,
                    'track_clicks'  => true,
                    'created_by'    => $ownerId,
                ]
            );
        }

        $this->command?->info('Seeded ' . count(CrmSequence::TEMPLATES) . ' CRM sequence templates (paused).');
    }
}
