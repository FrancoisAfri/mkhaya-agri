<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Core\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecruitmentBoardingSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $userId = User::first()?->id ?? 1;

            $category = Category::firstOrCreate(
                ['name' => 'Human Resources'],
                [
                    'active'      => 1,
                    'description' => 'Human Resources',
                    'icon'        => 'users-cog',
                ]
            );

            CategoryAccess::firstOrCreate(
                ['category_id' => $category->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => 5]
            );

            $modules = [
                [
                    'name'      => 'Recruitment',
                    'code_name' => 'recruitment',
                    'path'      => '/recruitment/jobs',
                    'icon'      => 'briefcase',
                    'ribbons'   => [
                        ['name' => 'Jobs',         'path' => 'recruitment/jobs',       'icon' => 'briefcase'],
                        ['name' => 'Candidates',   'path' => 'recruitment/candidates', 'icon' => 'user-tie'],
                        ['name' => 'Offers',       'path' => 'offers',                 'icon' => 'file-contract'],
                    ],
                ],
                [
                    'name'      => 'Boarding',
                    'code_name' => 'boarding',
                    'path'      => '/pre-boarding',
                    'icon'      => 'user-check',
                    'ribbons'   => [
                        ['name' => 'Pre-Boarding',          'path' => 'pre-boarding',          'icon' => 'clipboard-list'],
                        ['name' => 'Onboarding',            'path' => 'onboarding',            'icon' => 'graduation-cap'],
                        ['name' => 'Onboarding Templates',  'path' => 'onboarding/templates',  'icon' => 'layer-group'],
                    ],
                ],
            ];

            foreach ($modules as $order => $moduleData) {
                $module = Modules::firstOrCreate(
                    ['code_name' => $moduleData['code_name']],
                    [
                        'uuid'         => Str::uuid(),
                        'active'       => 1,
                        'category_id'  => $category->id,
                        'name'         => $moduleData['name'],
                        'path'         => $moduleData['path'],
                        'font_awesome' => $moduleData['icon'],
                    ]
                );

                ModuleAccess::firstOrCreate(
                    ['module_id' => $module->id, 'user_id' => $userId],
                    ['active' => 1, 'access_level' => 5]
                );

                foreach ($moduleData['ribbons'] as $index => $ribbonData) {
                    $ribbon = Ribbon::firstOrCreate(
                        ['module_id' => $module->id, 'ribbon_path' => $ribbonData['path']],
                        [
                            'uuid'         => Str::uuid(),
                            'active'       => 1,
                            'sort_order'   => $index + 1,
                            'ribbon_name'  => $ribbonData['name'],
                            'description'  => $ribbonData['name'],
                            'font_awesome' => $ribbonData['icon'],
                            'access_level' => 5,
                        ]
                    );

                    RibbonAccess::firstOrCreate(
                        ['ribbon_id' => $ribbon->id, 'user_id' => $userId],
                        ['active' => 1, 'access_level' => 5]
                    );
                }
            }
        });
    }
}
