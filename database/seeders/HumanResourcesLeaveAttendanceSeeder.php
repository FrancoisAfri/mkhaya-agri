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

class HumanResourcesLeaveAttendanceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $userId = User::query()->value('id') ?? 1;

            $this->cleanupLegacyHrManagementModule();

            $policyModule = Modules::query()->where('code_name', 'policy_enforcement')->first();
            if (! $policyModule) {
                throw new \RuntimeException('Policy module (code_name=policy_enforcement) was not found. Seed it first.');
            }

            $category = Category::query()->find($policyModule->category_id);
            if (! $category) {
                throw new \RuntimeException('Category linked to policy module was not found.');
            }

            CategoryAccess::firstOrCreate(
                ['category_id' => $category->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => 5]
            );

            // ── Leave Management ─────────────────────────────────────────────
            $leaveModule = $this->createModule(
                categoryId: $category->id,
                userId: $userId,
                moduleData: [
                    'name'      => 'Leave Management',
                    'code_name' => 'leave_management',
                    'path'      => '/leave-management',
                    'icon'      => 'calendar-check',
                ]
            );

            // Remove stale ribbons before re-seeding so access levels update on re-run
            $this->clearModuleRibbons($leaveModule);

            $this->createRibbons($leaveModule, $userId, [
                // ── Level 1: All employees ────────────────────────────────────
                // (Apply / My Applications / Balances / Calendar / Parental are
                //  accessible via quick-action buttons on the employee dashboard)
                ['name' => 'My Dashboard',          'path' => 'leave-management',                        'icon' => 'tachometer-alt', 'order' => 1,  'level' => 1],
                ['name' => 'Self Service',          'path' => 'my',                                      'icon' => 'user-circle',    'order' => 0,  'level' => 1],

                // ── Level 2: Team leaders / line managers ─────────────────────
                ['name' => 'Manager Dashboard',     'path' => 'leave-management/manager-dashboard',      'icon' => 'user-tie',       'order' => 2,  'level' => 2],
                ['name' => 'Pending Approvals',     'path' => 'leave-management/approvals',              'icon' => 'tasks',          'order' => 3,  'level' => 2],
                ['name' => 'Team Calendar',         'path' => 'leave-management/calendar',               'icon' => 'calendar-alt',   'order' => 4,  'level' => 2],

                // ── Level 3: HR coordinators / senior managers ────────────────
                ['name' => 'Workforce Dashboard',   'path' => 'leave-management/workforce-dashboard',    'icon' => 'users',          'order' => 5,  'level' => 3],
                ['name' => 'HR Dashboard',          'path' => 'leave-management/hr-dashboard',           'icon' => 'shield-alt',     'order' => 6,  'level' => 3],
                ['name' => 'Reports',               'path' => 'leave-management/reports',                'icon' => 'chart-bar',      'order' => 7,  'level' => 3],
                ['name' => 'Manual Allocations',    'path' => 'leave-management/manual-allocations',     'icon' => 'balance-scale',  'order' => 8,  'level' => 3],
                ['name' => 'Leave Import',          'path' => 'leave-management/import',                 'icon' => 'file-import',    'order' => 9,  'level' => 3],

                // ── Level 4: HR managers / department heads / executives ───────
                ['name' => 'Executive Dashboard',   'path' => 'leave-management/executive-dashboard',   'icon' => 'crown',          'order' => 10, 'level' => 4],
                ['name' => 'Leave Types',           'path' => 'leave-management/setup/leave-types',     'icon' => 'tags',           'order' => 11, 'level' => 4],
                ['name' => 'Leave Profiles',        'path' => 'leave-management/setup/profiles',        'icon' => 'user-cog',       'order' => 12, 'level' => 4],
                ['name' => 'Approval Config',       'path' => 'leave-management/setup/approval-config', 'icon' => 'sitemap',        'order' => 13, 'level' => 4],

                // ── Level 5: System administrators only ───────────────────────
                ['name' => 'Scheduled Reports',     'path' => 'leave-management/setup/scheduled-reports','icon' => 'calendar-clock', 'order' => 14, 'level' => 5],
                ['name' => 'Configuration',         'path' => 'leave-management/setup/configuration',   'icon' => 'sliders-h',      'order' => 15, 'level' => 5],
            ]);

            // ── Time & Attendance ────────────────────────────────────────────
            $attendanceModule = $this->createModule(
                categoryId: $category->id,
                userId: $userId,
                moduleData: [
                    'name'      => 'Time & Attendance',
                    'code_name' => 'time_attendance',
                    'path'      => '/attendance',
                    'icon'      => 'clock',
                ]
            );

            $this->clearModuleRibbons($attendanceModule);
            $this->createRibbons($attendanceModule, $userId, [
                // ── Level 1: All employees ────────────────────────────────────
                // Dashboard — overview stats, today's activity, quick clock-in widget
                ['name' => 'My Dashboard',       'path' => 'attendance',                   'icon' => 'tachometer-alt',  'order' => 1,  'level' => 1],
                // Kiosk — dedicated full-screen clock-in/out action
                ['name' => 'Clock In / Out',     'path' => 'attendance/clocking/kiosk',    'icon' => 'clock',           'order' => 2,  'level' => 1],

                // ── Level 2: Supervisors / managers ───────────────────────────
                // Records hub — attendance records + missed clockings in one place
                ['name' => 'Records',            'path' => 'attendance/records',           'icon' => 'user-clock',      'order' => 3,  'level' => 2],

                // ── Level 3: HR / HR managers — Setup ─────────────────────────
                // Setup hub — shifts, work calendars, and statuses under one ribbon
                ['name' => 'Setup',              'path' => 'attendance/shifts',            'icon' => 'sliders-h',       'order' => 4,  'level' => 3],

                // ── Level 3: Intelligence ────────────────────────────────────
                // Intelligence hub — anomalies, risk scores, patterns, AI chat
                ['name' => 'Intelligence',       'path' => 'attendance/intelligence',      'icon' => 'robot',           'order' => 5,  'level' => 3],

                // ── Level 3: Workforce Health ─────────────────────────────────
                // Workforce Health hub — burnout, pressure forecasts, wellness signals
                ['name' => 'Workforce Health',   'path' => 'attendance/workforce',         'icon' => 'heartbeat',       'order' => 6,  'level' => 3],

                // ── Level 3: Roster ───────────────────────────────────────────
                ['name' => 'Rosters',            'path' => 'attendance/roster',            'icon' => 'calendar-check',  'order' => 7,  'level' => 3],

                // ── Level 3: Discipline ───────────────────────────────────────
                // Discipline hub — cases + repeat offenders
                ['name' => 'Discipline',         'path' => 'attendance/discipline',        'icon' => 'gavel',           'order' => 8,  'level' => 3],

                // ── Level 3: Policy & Compliance ─────────────────────────────
                // Policy hub — engine, violations, compliance, exceptions
                ['name' => 'Policy & Compliance','path' => 'attendance/policy',            'icon' => 'balance-scale',   'order' => 9,  'level' => 3],

                // ── Level 3: Productivity Analytics ──────────────────────────
                // Productivity hub — employee scores + department efficiency (built-in tabs)
                ['name' => 'Productivity',       'path' => 'attendance/productivity',      'icon' => 'chart-line',      'order' => 10, 'level' => 3],

                // ── Level 3: Reports & Executive ─────────────────────────────
                ['name' => 'Reports',            'path' => 'attendance/reports',                      'icon' => 'file-chart-line',   'order' => 11, 'level' => 3],
                ['name' => 'Scheduled Reports',  'path' => 'attendance/reports/scheduled',            'icon' => 'calendar-clock',    'order' => 12, 'level' => 3],
                ['name' => 'Executive',          'path' => 'attendance/executive',                    'icon' => 'chart-areaspline',  'order' => 13, 'level' => 3],

                // ── Level 3: Analytics ────────────────────────────────────────
                ['name' => 'Late-Arrival Heatmap', 'path' => 'attendance/analytics/heatmap',         'icon' => 'fire',              'order' => 14, 'level' => 3],
                ['name' => 'BCEA Compliance',      'path' => 'attendance/bcea',                      'icon' => 'balance-scale-left', 'order' => 15, 'level' => 3],
                ['name' => 'Absenteeism Cost',     'path' => 'attendance/analytics/absenteeism-cost','icon' => 'money-bill-trend-up', 'order' => 16, 'level' => 3],

                // ── Level 4: HR Admins / Administrators ───────────────────────
                ['name' => 'Audit & Logs',       'path' => 'attendance/audit',                       'icon' => 'clipboard-list',    'order' => 17, 'level' => 4],
                ['name' => 'Settings',           'path' => 'attendance/settings',                    'icon' => 'cog',               'order' => 18, 'level' => 4],
            ]);

            $this->cleanupDuplicateHumanResourcesCategory((int) $category->id);
        });
    }

    private function createModule(int $categoryId, int $userId, array $moduleData): Modules
    {
        $module = Modules::firstOrCreate(
            ['code_name' => $moduleData['code_name']],
            [
                'uuid'         => (string) Str::uuid(),
                'active'       => 1,
                'category_id'  => $categoryId,
                'name'         => $moduleData['name'],
                'path'         => $moduleData['path'],
                'font_awesome' => $moduleData['icon'],
            ]
        );

        if ((int) $module->category_id !== $categoryId) {
            $module->category_id = $categoryId;
            $module->save();
        }

        ModuleAccess::firstOrCreate(
            ['module_id' => $module->id, 'user_id' => $userId],
            ['active' => 1, 'access_level' => 5]
        );

        return $module;
    }

    private function clearModuleRibbons(Modules $module): void
    {
        $ribbonIds = Ribbon::query()->where('module_id', $module->id)->pluck('id');
        if ($ribbonIds->isNotEmpty()) {
            RibbonAccess::query()->whereIn('ribbon_id', $ribbonIds)->delete();
            Ribbon::query()->whereIn('id', $ribbonIds)->delete();
        }
    }

    private function createRibbons(Modules $module, int $userId, array $ribbons): void
    {
        foreach ($ribbons as $ribbonData) {
            $level  = $ribbonData['level'] ?? 5;
            $ribbon = Ribbon::create([
                'uuid'         => (string) Str::uuid(),
                'module_id'    => $module->id,
                'active'       => 1,
                'sort_order'   => $ribbonData['order'],
                'access_level' => $level,
                'ribbon_name'  => $ribbonData['name'],
                'ribbon_path'  => $ribbonData['path'],
                'description'  => $ribbonData['name'],
                'font_awesome' => $ribbonData['icon'],
            ]);

            RibbonAccess::create([
                'ribbon_id'    => $ribbon->id,
                'user_id'      => $userId,
                'active'       => 1,
                'access_level' => $level,
            ]);
        }
    }

    private function cleanupLegacyHrManagementModule(): void
    {
        $legacyModule = Modules::query()->where('code_name', 'hr_management')->first();
        if (! $legacyModule) {
            return;
        }

        $legacyRibbonIds = Ribbon::query()
            ->where('module_id', $legacyModule->id)
            ->pluck('id');

        if ($legacyRibbonIds->isNotEmpty()) {
            RibbonAccess::query()->whereIn('ribbon_id', $legacyRibbonIds)->delete();
            Ribbon::query()->whereIn('id', $legacyRibbonIds)->delete();
        }

        ModuleAccess::query()->where('module_id', $legacyModule->id)->delete();
        Modules::query()->where('id', $legacyModule->id)->delete();
    }

    private function cleanupDuplicateHumanResourcesCategory(int $keepCategoryId): void
    {
        $duplicates = Category::query()
            ->whereRaw('LOWER(name) = ?', ['human resources'])
            ->where('id', '!=', $keepCategoryId)
            ->get();

        foreach ($duplicates as $duplicate) {
            $moduleCount = Modules::query()->where('category_id', $duplicate->id)->count();
            if ($moduleCount > 0) {
                continue;
            }

            CategoryAccess::query()->where('category_id', $duplicate->id)->delete();
            $duplicate->delete();
        }
    }
}
