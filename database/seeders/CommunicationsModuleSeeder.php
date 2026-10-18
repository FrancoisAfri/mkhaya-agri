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

class CommunicationsModuleSeeder extends Seeder
{
    /**
     * Entry point of the seeder
     * - Creates main category
     * - Creates modules
     * - Creates ribbons (sub-menus)
     * - Assigns access permissions
     */
    public function run(): void
    {
        DB::transaction(function () {

            $userId = User::first()?->id ?? 1;

            $category = $this->createCategory($userId);

            $this->createCategoryAccess($category->id, $userId);

            foreach ($this->modulesData() as $moduleData) {

                $module = $this->createModule($category->id, $moduleData);

                foreach ($moduleData['ribbons'] as $index => $ribbonData) {
                    $this->createRibbon($module, $ribbonData, $index + 1, $userId);
                }

                $this->createModuleAccess($module->id, $userId);
            }
        });
    }

    /**
     * 📁 Create the main category
     */
    private function createCategory(int $userId): Category
    {
        // Rename legacy category if it still exists
        Category::where('name', 'Communications & Resources')
            ->update(['name' => 'Operations & Support', 'icon' => 'headset']);

        return Category::firstOrCreate(
            ['name' => 'Operations & Support'],
            [
                'active'      => 1,
                'description' => 'Internal operations, support, and service management',
                'icon'        => 'headset',
            ]
        );
    }

    /**
     *  Create a module
     */
    private function createModule(int $categoryId, array $data): Modules
    {
        return Modules::firstOrCreate(
            ['code_name' => $data['code_name']],
            [
                'uuid'         => Str::uuid(),
                'active'       => 1,
                'category_id'  => $categoryId,
                'name'         => $data['name'],
                'path'         => $data['path'],
                'font_awesome' => $data['icon'],
            ]
        );
    }

    /**
     *  Create a ribbon (submenu item)
     */
    private function createRibbon($module, array $data, int $order, int $userId): void
    {
        $ribbon = Ribbon::firstOrCreate(
            [
                'module_id'   => $module->id,
                'ribbon_path' => $data['path'],
            ],
            [
                'uuid'         => Str::uuid(),
                'active'       => 1,
                'sort_order'   => $order,
                'ribbon_name'  => $data['name'],
                'description'  => $data['name'],
                'font_awesome' => $data['icon'] ?? 'circle',
                'access_level' => 5,
            ]
        );

        $this->createRibbonAccess($ribbon->id, $userId);
    }

    /**
     * 🔐 Grant category access to user
     */
    private function createCategoryAccess(int $categoryId, int $userId): void
    {
        CategoryAccess::firstOrCreate(
            [
                'category_id' => $categoryId,
                'user_id'     => $userId,
            ],
            [
                'active'       => 1,
                'access_level' => 5,
            ]
        );
    }

    /**
     * 🔐 Grant module access to user
     */
    private function createModuleAccess(int $moduleId, int $userId): void
    {
        ModuleAccess::firstOrCreate(
            [
                'module_id' => $moduleId,
                'user_id'   => $userId,
            ],
            [
                'active'       => 1,
                'access_level' => 5,
            ]
        );
    }

    /**
     * 🔐 Grant ribbon access to user
     */
    private function createRibbonAccess(int $ribbonId, int $userId): void
    {
        RibbonAccess::firstOrCreate(
            [
                'ribbon_id' => $ribbonId,
                'user_id'   => $userId,
            ],
            [
                'active'       => 1,
                'access_level' => 5,
            ]
        );
    }

    /**
     *  Module structure definition (source of truth for CMS menu)
     */
    private function modulesData(): array
    {
        return [
            [
                'name'      => 'Content Management',
                'code_name' => 'content_management',
                'path'      => '/cms/content-management',
                'icon'      => 'layer-group',

                'ribbons' => [
                    ['name' => 'Dashbaord', 'path' => 'cms/dashaboard-cm', 'icon' => 'chart-line'],
                    ['name' => 'CEO Message', 'path' => 'cms/ceo-message', 'icon' => 'microphone'],
                    ['name' => 'News', 'path' => 'cms/news', 'icon' => 'newspaper'],
                    ['name' => 'Reports', 'path' => 'cms/reports-cm', 'icon' => 'chart-bar'],
                    ['name' => 'Events', 'path' => 'cms/events', 'icon' => 'calendar-days'],
                    ['name' => 'Announcements', 'path' => 'cms/announcements', 'icon' => 'bullhorn'],
                    ['name' => 'Employee & Team Spotlight', 'path' => 'cms/employee-team', 'icon' => 'trophy'],
                    ['name' => 'Internal Notice', 'path' => 'cms/internal-notice', 'icon' => 'thumbtack'],
                    ['name' => 'Email Campaigns', 'path' => 'cms/campaigns',       'icon' => 'envelope-open-text'],
                ],
            ],

            [
                'name'      => 'Resources',
                'code_name' => 'resources',
                'path'      => '/cms/resources',
                'icon'      => 'book',

                'ribbons' => [
                    ['name' => 'Dashbaord', 'path' => 'cms/dashaboard-resources', 'icon' => 'chart-line'],
                    ['name' => 'Reports', 'path' => 'cms/reports-resources', 'icon' => 'chart-bar'],
                    ['name' => 'Forms', 'path' => 'cms/forms', 'icon' => 'file-signature'],
                    ['name' => 'Corporate Branding', 'path' => 'cms/corporate-branding', 'icon' => 'tag'],
                    ['name' => 'NDA and Legal', 'path' => 'cms/nda-legal', 'icon' => 'scale-balanced'],
                    ['name' => 'Employee Handbook', 'path' => 'cms/employee-handbook', 'icon' => 'book-open'],
                    ['name' => 'Health and Safety', 'path' => 'cms/health-safety', 'icon' => 'shield-heart'],
                    ['name' => 'Templates', 'path' => 'cms/templates', 'icon' => 'clone'],
                    ['name' => 'Training Material', 'path' => 'cms/training-material', 'icon' => 'graduation-cap'],
                    ['name' => 'SOPs', 'path' => 'cms/sops', 'icon' => 'clipboard-list'],
                    ['name' => 'Downloads', 'path' => 'cms/downloads', 'icon' => 'download'],
                ],
            ],
        ];
    }
}
