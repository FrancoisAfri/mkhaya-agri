<?php

use App\Modules\CRM\Support\CrmHubs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nav-reduction rework for the CRM and Helpdesk modules — the same pattern already applied to
 * Content Marketing. CRM's ~43 feature ribbons collapse to Dashboard + 7 hubs; Helpdesk's ~32
 * (with legacy duplicates and a "Helpdeks" typo) collapse to Dashboard + 6 hubs. Both hub sets
 * are sourced from their ModuleHubs config (single source of truth).
 *
 * Feature pages are unchanged and still reachable (as hub cards and by direct URL); only the nav
 * shrinks. Ribbons are global (shared across tenants), access is per-user, so this runs once.
 * Any how-to tied to a retired feature ribbon is re-pointed to its new hub ribbon BEFORE the old
 * ribbon is deleted (module_how_tos + security_ribbons_access both cascade-delete on ribbon
 * removal). Retention is keyed by ribbon NAME, so a legacy ribbon sharing a hub's path is still
 * retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'crm'       => CrmHubs::class,
        ] as $code => $hubsClass) {
            $this->consolidate($code, $hubsClass);
        }
    }

    private function consolidate(string $code, string $hubsClass): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', $code)->value('id');
        if (! $moduleId) {
            return; // module not installed on this deployment.
        }

        // 1. Upsert the hub ribbons; capture path => id, and the retained names.
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

        // 2. Map every retired feature path => the hub path that now hosts it (card => hub).
        //    Paths are compared without query strings.
        $cardToHub = [];
        foreach ($hubsClass::hubs() as $hub) {
            foreach ($hub['cards'] as $card) {
                $cardToHub[$this->basePath($card['path'])] = $hub['path'];
            }
        }

        $dashboardPath = $hubsClass::direct()[0]['path'];
        $dashboardId = $hubIdByPath[$dashboardPath] ?? array_values($hubIdByPath)[0];

        // 3. Retire every ribbon that is not one of the retained hub ribbons (matched by name):
        //    re-point its how-tos to the hosting hub ribbon, then delete it (access rows cascade).
        $old = DB::table('security_module_ribbons')
            ->where('module_id', $moduleId)
            ->whereNotIn('ribbon_name', $keepNames)
            ->get(['id', 'ribbon_path']);

        foreach ($old as $ribbon) {
            $base = $this->basePath($ribbon->ribbon_path);
            $hubPath = $cardToHub[$base] ?? ($hubIdByPath[$base] ?? null ? $ribbon->ribbon_path : null);
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
        // Irreversible: retired feature ribbons are re-created by ModuleSeeders on demand, and
        // re-pointed how-tos cannot be un-ambiguously restored. No-op.
    }
};
