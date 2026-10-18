<?php

use App\Support\Hubs\Modules\AccountingHubs;
use App\Support\Hubs\Modules\AssetHubs;
use App\Support\Hubs\Modules\AttendanceHubs;
use App\Support\Hubs\Modules\BoardHubs;
use App\Support\Hubs\Modules\DisciplinaryHubs;
use App\Support\Hubs\Modules\FleetHubs;
use App\Support\Hubs\Modules\LeaveHubs;
use App\Support\Hubs\Modules\LmsHubs;
use App\Support\Hubs\Modules\PayrollHubs;
use App\Support\Hubs\Modules\PerformanceHubs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nav-reduction rework for the operations & HR modules — the same pattern already applied to
 * Content Marketing, CRM and Helpdesk. Each module's feature ribbons collapse into a Dashboard +
 * a handful of role-tiered hubs, sourced from its ModuleHubs config (single source of truth).
 *
 * Feature pages are unchanged and still reachable (as hub cards and by direct URL); only the nav
 * shrinks. HR-sensitive tiers are enforced: e.g. Payroll exposes only "My Payslips" to normal
 * users, everything else is ADMIN+.
 *
 * Ribbons are global; access is per-user. How-tos tied to a retired feature ribbon are re-pointed
 * to their new hub ribbon BEFORE the old ribbon is deleted (module_how_tos + security_ribbons_access
 * cascade-delete on ribbon removal). Retention is keyed by ribbon NAME, so legacy duplicate ribbons
 * (e.g. Attendance/Leave "My Dashboard" vs "Dashboard") are cleaned up.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'asset_management'      => AssetHubs::class,
            'fleet_management'      => FleetHubs::class,
            'accounting'            => AccountingHubs::class,
            'board_committee'       => BoardHubs::class,
            'disciplinary'          => DisciplinaryHubs::class,
            'payroll'               => PayrollHubs::class,
            'learning_management'   => LmsHubs::class,
            'time_attendance'       => AttendanceHubs::class,
            'leave_management'      => LeaveHubs::class,
            'performance_appraisal' => PerformanceHubs::class,
        ] as $code => $hubsClass) {
            $this->consolidate($code, $hubsClass);
        }
    }

    private function consolidate(string $code, string $hubsClass): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', $code)->value('id');
        if (! $moduleId) {
            return;
        }

        $hubIdByPath = [];
        $keepNames = [];
        foreach ($hubsClass::navRibbons() as $i => $r) {
            $keepNames[] = $r['name'];
            $existing = DB::table('security_module_ribbons')
                ->where('module_id', $moduleId)->where('ribbon_name', $r['name'])->first();

            $attrs = [
                'ribbon_path'  => $r['path'],
                'font_awesome' => $r['icon'],
                'description'  => $r['name'],
                'access_level' => $r['access_level'],
                'active'       => 1,
                'sort_order'   => $i + 1,
                'updated_at'   => now(),
            ];

            if ($existing) {
                DB::table('security_module_ribbons')->where('id', $existing->id)->update($attrs);
                $hubIdByPath[$r['path']] = $existing->id;
            } else {
                $hubIdByPath[$r['path']] = DB::table('security_module_ribbons')->insertGetId(array_merge($attrs, [
                    'module_id'   => $moduleId,
                    'ribbon_name' => $r['name'],
                    'uuid'        => (string) Str::uuid(),
                    'created_at'  => now(),
                ]));
            }
        }

        // card base-path => hub path (query stripped), for re-pointing retired ribbons' how-tos.
        $cardToHub = [];
        foreach ($hubsClass::hubs() as $hub) {
            foreach ($hub['cards'] as $card) {
                $cardToHub[$this->basePath($card['path'])] = $hub['path'];
            }
        }

        $dashboardPath = $hubsClass::direct()[0]['path'];
        $dashboardId = $hubIdByPath[$dashboardPath] ?? array_values($hubIdByPath)[0];

        $old = DB::table('security_module_ribbons')
            ->where('module_id', $moduleId)
            ->whereNotIn('ribbon_name', $keepNames)
            ->get(['id', 'ribbon_path']);

        foreach ($old as $ribbon) {
            $base = $this->basePath($ribbon->ribbon_path);
            $hubPath = $cardToHub[$base] ?? null;
            $targetId = ($hubPath && isset($hubIdByPath[$hubPath])) ? $hubIdByPath[$hubPath] : $dashboardId;

            DB::table('module_how_tos')
                ->where('ribbon_id', $ribbon->id)
                ->update(['ribbon_id' => $targetId, 'updated_at' => now()]);

            DB::table('security_module_ribbons')->where('id', $ribbon->id)->delete();
        }
    }

    private function basePath(string $path): string
    {
        return trim(explode('?', $path, 2)[0], '/');
    }

    public function down(): void
    {
        // Irreversible: retired ribbons are re-created by the seeders on demand. No-op.
    }
};
