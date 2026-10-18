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
 * Re-seeds only the Payroll module's ribbons. Idempotent — uses
 * firstOrCreate keyed on (module_id, ribbon_path).
 */
class PayrollRibbonSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $module = Modules::where('code_name', 'payroll')->first();
            if (!$module) {
                $this->command?->warn('Payroll module not found. Run ModuleSeeders first.');
                return;
            }

            $userId = User::first()?->id ?? 1;

            $ribbons = [
                ['name' => 'Dashboard',           'path' => 'payroll/dashboard',          'icon' => 'gauge-high'],
                ['name' => 'Payroll Runs',        'path' => 'payroll/runs',               'icon' => 'list-check'],
                ['name' => 'Anomalies',           'path' => 'payroll/anomalies',          'icon' => 'triangle-exclamation'],
                ['name' => 'Cross-Checks',        'path' => 'payroll/cross-checks',       'icon' => 'clipboard-check'],
                ['name' => 'Leakage Detection',   'path' => 'payroll/leakage',            'icon' => 'money-bill-trend-up'],
                ['name' => 'Ghost Employees',     'path' => 'payroll/ghost-employees',    'icon' => 'user-slash'],
                ['name' => 'Behaviour Analytics', 'path' => 'payroll/behavior',           'icon' => 'chart-bar'],
                ['name' => 'AI Assistant',        'path' => 'payroll/ai-assistant',       'icon' => 'robot'],
                ['name' => 'Change Log',          'path' => 'payroll/change-log',         'icon' => 'clock-rotate-left'],
                ['name' => 'Approvals',           'path' => 'payroll/approvals',          'icon' => 'check-double'],
                ['name' => 'Simulations',         'path' => 'payroll/simulations',        'icon' => 'flask'],
                ['name' => 'Forecasting',         'path' => 'payroll/forecasting',        'icon' => 'chart-line'],
                ['name' => 'Compliance',          'path' => 'payroll/compliance',         'icon' => 'shield-halved'],
                ['name' => 'Auto-Corrections',    'path' => 'payroll/corrections',        'icon' => 'wand-magic-sparkles'],
                ['name' => 'Narrative Reports',   'path' => 'payroll/narrative-reports',  'icon' => 'file-lines'],
                ['name' => 'Analytics',           'path' => 'payroll/analytics',          'icon' => 'chart-mixed'],
                ['name' => 'Reports',             'path' => 'payroll/reports',            'icon' => 'file-chart-column'],
                ['name' => 'My Payslips',         'path' => 'payroll/my-payslips',                 'icon' => 'receipt'],
                ['name' => 'Scheduled Reports',   'path' => 'payroll/settings/scheduled-reports', 'icon' => 'calendar-check'],
                ['name' => 'Settings',            'path' => 'payroll/settings',                   'icon' => 'sliders'],
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
