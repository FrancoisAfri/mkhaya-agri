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

class PerformanceAppraisalSeeder extends Seeder
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

            $this->grantCategoryAccess($category->id, $userId);

            $module = Modules::updateOrCreate(
                ['code_name' => 'performance_appraisal'],
                [
                    'uuid'         => Str::uuid(),
                    'active'       => 1,
                    'category_id'  => $category->id,
                    'name'         => 'Performance Management',
                    'path'         => '/performance',
                    'font_awesome' => 'chart-line',
                ]
            );

            $this->grantModuleAccess($module->id, $userId);

            $ribbons = collect(\App\Support\Hubs\Modules\PerformanceHubs::navRibbons())
                ->map(fn ($r, $i) => ['name' => $r['name'], 'path' => $r['path'], 'icon' => $r['icon'], 'order' => $i + 1, 'access_level' => $r['access_level']])
                ->all();

            foreach ($ribbons as $data) {
                $ribbon = Ribbon::updateOrCreate(
                    ['module_id' => $module->id, 'ribbon_path' => $data['path']],
                    [
                        'uuid'         => Str::uuid(),
                        'active'       => 1,
                        'sort_order'   => $data['order'],
                        'ribbon_name'  => $data['name'],
                        'description'  => $data['name'],
                        'font_awesome' => $data['icon'],
                        'access_level' => $data['access_level'],
                    ]
                );

                $this->grantRibbonAccess($ribbon->id, $userId);
            }
        });
    }

    private function grantCategoryAccess(int $categoryId, int $userId): void
    {
        CategoryAccess::firstOrCreate(
            ['category_id' => $categoryId, 'user_id' => $userId],
            ['active' => 1, 'access_level' => 5]
        );
    }

    private function grantModuleAccess(int $moduleId, int $userId): void
    {
        ModuleAccess::firstOrCreate(
            ['module_id' => $moduleId, 'user_id' => $userId],
            ['active' => 1, 'access_level' => 5]
        );
    }

    private function grantRibbonAccess(int $ribbonId, int $userId): void
    {
        RibbonAccess::firstOrCreate(
            ['ribbon_id' => $ribbonId, 'user_id' => $userId],
            ['active' => 1, 'access_level' => 5]
        );
    }
}
