<?php

namespace Database\Seeders;

use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Core\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Surfaces the Payroll Bureau module in the Human Resources menu: module access
 * + ribbons + ribbon access for admin users. Idempotent. The module row itself
 * is created by the register_payroll_bureau_module migration.
 */
class PayrollBureauModuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $module = Modules::where('code_name', 'payroll_bureau')->first();
            if (! $module) {
                $this->command?->warn('payroll_bureau module row missing — run migrations first.');
                return;
            }
            $module->update(['active' => 1]);

            // Grant to admins; fall back to all users if no admin role is found.
            $admins = User::query()->get()->filter(fn (User $u) => $u->hasRole(['super admin', 'administrator']));
            if ($admins->isEmpty()) {
                $admins = User::query()->get();
            }

            $ribbons = [
                ['name' => 'Bureau Companies', 'path' => 'payroll/bureau', 'icon' => 'building'],
            ];

            foreach ($admins as $user) {
                ModuleAccess::firstOrCreate(
                    ['module_id' => $module->id, 'user_id' => $user->id],
                    ['active' => 1, 'access_level' => 5]
                );
            }

            foreach ($ribbons as $index => $data) {
                $ribbon = Ribbon::firstOrCreate(
                    ['module_id' => $module->id, 'ribbon_path' => $data['path']],
                    [
                        'uuid'         => (string) Str::uuid(),
                        'ribbon_name'  => $data['name'],
                        'description'  => $data['name'],
                        'font_awesome' => $data['icon'],
                        'access_level' => 5,
                        'sort_order'   => $index + 1,
                        'active'       => 1,
                    ]
                );
                $ribbon->update(['ribbon_name' => $data['name'], 'active' => 1]);

                foreach ($admins as $user) {
                    RibbonAccess::firstOrCreate(
                        ['ribbon_id' => $ribbon->id, 'user_id' => $user->id],
                        ['active' => 1, 'access_level' => 5]
                    );
                }
            }
        });

        $this->command?->info('Payroll Bureau module surfaced under Human Resources.');
    }
}
