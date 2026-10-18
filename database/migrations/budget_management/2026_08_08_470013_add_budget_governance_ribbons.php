<?php

use App\Support\Hubs\Modules\BudgetHubs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Put the new Budget pages in the sidebar on installations that already exist.
 *
 * `BudgetHubs` is the single source of truth for this module's navigation, and it now declares two
 * groups it did not have — Governance (the approvals queue, tracking, and the approval designer)
 * and Insights (BI). This applies the same consolidation `hubs:sync` performs, so a site already
 * running gets the new hubs without anybody having to remember to run a command.
 *
 * Access is copied from the Dashboard ribbon: a new ribbon nobody is granted is invisible even to
 * the administrator who deployed it, and a feature reachable only by typing a URL is one nobody
 * uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'budget_management')->value('id');

        if (! $moduleId) {
            return;
        }

        $grantees = DB::table('security_module_ribbons as r')
            ->join('security_ribbons_access as a', 'a.ribbon_id', '=', 'r.id')
            ->where('r.module_id', $moduleId)
            ->pluck('a.user_id')
            ->unique()
            ->values();

        $hasAccessUuid = Schema::hasColumn('security_ribbons_access', 'uuid');

        foreach (BudgetHubs::navRibbons() as $index => $ribbon) {
            $existing = DB::table('security_module_ribbons')
                ->where('module_id', $moduleId)
                ->where('ribbon_name', $ribbon['name'])
                ->first();

            $attrs = [
                'ribbon_path'  => $ribbon['path'],
                'font_awesome' => $ribbon['icon'],
                'description'  => $ribbon['name'],
                'access_level' => $ribbon['access_level'],
                'active'       => 1,
                'sort_order'   => $index + 1,
                'updated_at'   => now(),
            ];

            if ($existing) {
                DB::table('security_module_ribbons')->where('id', $existing->id)->update($attrs);

                continue;
            }

            $id = DB::table('security_module_ribbons')->insertGetId($attrs + [
                'module_id'   => $moduleId,
                'ribbon_name' => $ribbon['name'],
                'uuid'        => (string) Str::uuid(),
                'created_at'  => now(),
            ]);

            foreach ($grantees as $userId) {
                $row = ['ribbon_id' => $id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()];

                if ($hasAccessUuid) {
                    $row['uuid'] = (string) Str::uuid();
                }

                DB::table('security_ribbons_access')->insert($row);
            }
        }

        $this->pruneStale($moduleId);
    }

    /**
     * Retire the flat ribbons the hub groups replace.
     *
     * Without this a site ends up showing both shapes at once — a "Budgets" hub beside a flat
     * "New Budget" and "Settings" that are already cards inside it — which reads as three features
     * where there is one. `hubs:sync` does the same thing; doing it here means a deploy is enough
     * and nobody has to remember the command.
     *
     * How-tos are moved before the ribbon goes, because `module_how_tos` cascade-deletes with it
     * and dropping a retired ribbon would silently take its help articles with it.
     */
    private function pruneStale(int $moduleId): void
    {
        $keep = collect(BudgetHubs::navRibbons())->pluck('path')->all();

        $stale = DB::table('security_module_ribbons')
            ->where('module_id', $moduleId)
            ->whereNotIn('ribbon_path', $keep)
            ->get(['id', 'ribbon_path']);

        if ($stale->isEmpty()) {
            return;
        }

        $dashboard = DB::table('security_module_ribbons')
            ->where('module_id', $moduleId)
            ->where('ribbon_path', 'budgets')
            ->value('id');

        foreach ($stale as $ribbon) {
            if ($dashboard && Schema::hasTable('module_how_tos')) {
                DB::table('module_how_tos')->where('ribbon_id', $ribbon->id)
                    ->update(['ribbon_id' => $dashboard, 'updated_at' => now()]);
            }

            DB::table('security_ribbons_access')->where('ribbon_id', $ribbon->id)->delete();
            DB::table('security_module_ribbons')->where('id', $ribbon->id)->delete();
        }
    }

    public function down(): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'budget_management')->value('id');

        if (! $moduleId) {
            return;
        }

        // Only the groups this migration introduced. Removing the ones that were here before would
        // leave a site worse off than it started.
        $ids = DB::table('security_module_ribbons')
            ->where('module_id', $moduleId)
            ->whereIn('ribbon_name', ['Governance', 'Insights'])
            ->pluck('id');

        DB::table('security_ribbons_access')->whereIn('ribbon_id', $ids)->delete();
        DB::table('security_module_ribbons')->whereIn('id', $ids)->delete();
    }
};
