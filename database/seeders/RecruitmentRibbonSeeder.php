<?php

namespace Database\Seeders;

use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Core\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-seeds only the Recruitment module's ribbons. Idempotent — uses
 * firstOrCreate keyed on (module_id, ribbon_path).
 */
class RecruitmentRibbonSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $module = Modules::where('code_name', 'recruitment')->first();
            if (!$module) {
                $this->command?->warn('Recruitment module not found. Run ModuleSeeders first.');
                return;
            }

            $userId = User::first()?->id ?? 1;

            $ribbons = [
                ['name' => 'Dashboard',           'path' => 'recruitment/dashboard',                            'icon' => 'gauge-high'],
                ['name' => 'Jobs',                'path' => 'recruitment/jobs',                                  'icon' => 'briefcase'],
                ['name' => 'Vacancies',           'path' => 'recruitment/vacancies',                             'icon' => 'bullhorn'],
                ['name' => 'Requisitions',        'path' => 'recruitment/requisitions',                          'icon' => 'file-signature'],
                ['name' => 'Candidates',          'path' => 'recruitment/candidates',                            'icon' => 'user-tie'],
                ['name' => 'Approvals',           'path' => 'recruitment/approvals/pending',                     'icon' => 'check-double'],
                ['name' => 'Succession Planning', 'path' => 'recruitment/succession',                            'icon' => 'sitemap'],
                ['name' => 'Internal Job Board',  'path' => 'recruitment/internal/job-board',                    'icon' => 'building-user'],
                ['name' => 'Offers',              'path' => 'offers',                                            'icon' => 'file-contract'],
                ['name' => 'Reports',             'path' => 'recruitment/reports',                               'icon' => 'chart-line'],
                ['name' => 'Approval Workflows',  'path' => 'recruitment/settings/approval-workflows',           'icon' => 'diagram-project'],
                ['name' => 'Social Accounts',    'path' => 'recruitment/settings/social-accounts',              'icon' => 'share-nodes'],
            ];

            foreach ($ribbons as $index => $data) {
                $ribbon = Ribbon::firstOrCreate(
                    [
                        'module_id'   => $module->id,
                        'ribbon_path' => $data['path'],
                    ],
                    [
                        'uuid'         => Str::uuid(),
                        'active'       => 1,
                        'sort_order'   => $index + 1,
                        'ribbon_name'  => $data['name'],
                        'description'  => $data['name'],
                        'font_awesome' => $data['icon'],
                        'access_level' => 5,
                    ],
                );

                $ribbon->update([
                    'sort_order'   => $index + 1,
                    'ribbon_name'  => $data['name'],
                    'font_awesome' => $data['icon'],
                    'active'       => 1,
                ]);

                RibbonAccess::firstOrCreate(
                    ['ribbon_id' => $ribbon->id, 'user_id' => $userId],
                    ['active' => 1, 'access_level' => 5],
                );
            }
        });
    }
}
