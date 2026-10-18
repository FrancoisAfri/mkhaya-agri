<?php

namespace App\Console\Commands;

use App\Support\Hubs\HubRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reload a module's navigation hub ribbons on a live site — re-applies the same consolidation the
 * migrations perform, but on demand and idempotently. Use after deploying hub changes, or to fix a
 * site whose ribbons drifted:
 *
 *   php artisan hubs:sync                 # sync every hubbed module
 *   php artisan hubs:sync payroll         # sync one module by code_name
 *   php artisan hubs:sync --prune=0       # add/update hub ribbons but keep legacy ones
 *
 * For each module it upserts the hub ribbons from the ModuleHubs config (single source of truth),
 * re-points any how-tos on retired feature ribbons to their new hub ribbon, then (unless --prune=0)
 * deletes the retired ribbons. Safe to run repeatedly.
 */
class SyncModuleHubs extends Command
{
    protected $signature = 'hubs:sync {module? : Module code_name (omit to sync all hubbed modules)} {--prune=1 : Delete retired feature ribbons (0 to keep them)}';

    protected $description = 'Reload/re-consolidate a module\'s navigation hub ribbons from its ModuleHubs config';

    /** code_name => hubs config class (registry modules + Content Marketing). */
    private function modules(): array
    {
        return HubRegistry::all();
    }

    public function handle(): int
    {
        $only = $this->argument('module');
        $prune = (string) $this->option('prune') !== '0';
        $modules = $this->modules();

        if ($only) {
            if (! isset($modules[$only])) {
                $this->error("Unknown or non-hubbed module: {$only}");
                $this->line('Available: ' . implode(', ', array_keys($modules)));

                return self::FAILURE;
            }
            $modules = [$only => $modules[$only]];
        }

        $rows = [];
        foreach ($modules as $code => $hubsClass) {
            $rows[] = $this->sync($code, $hubsClass, $prune);
        }

        $this->table(['Module', 'Before', 'After', 'Retired', 'How-tos re-pointed'], $rows);
        $this->info('Hub sync complete.' . ($prune ? '' : ' (prune disabled — legacy ribbons kept)'));

        return self::SUCCESS;
    }

    /** @return array{0:string,1:int,2:int,3:int|string,4:int} */
    private function sync(string $code, string $hubsClass, bool $prune): array
    {
        $moduleId = DB::table('security_modules')->where('code_name', $code)->value('id');
        if (! $moduleId) {
            return [$code, 0, 0, 'module not installed', 0];
        }

        $before = DB::table('security_module_ribbons')->where('module_id', $moduleId)->count();

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

        $cardToHub = [];
        foreach ($hubsClass::hubs() as $hub) {
            foreach ($hub['cards'] as $card) {
                $cardToHub[$this->basePath($card['path'])] = $hub['path'];
            }
        }

        /*
         * Where a retired ribbon's how-tos go when no hub claims them.
         *
         * The module's first direct entry, when it has one — that is its landing page, and a how-to
         * parked there is at least findable. A module whose entries all live inside hubs has no
         * direct entry at all, and this read `direct()[0]` unconditionally: emptying that array to
         * remove a duplicated sidebar link took the whole sync down with "Undefined array key 0".
         *
         * Falling back to the first hub is right rather than merely safe. A how-to has to point at
         * some ribbon or it stops being reachable from the assistant, and the alternative — leaving
         * it pointed at a ribbon that has just been deleted — is a broken link nobody sees until
         * somebody asks the question it answers.
         */
        $direct = $hubsClass::direct();
        $dashboardPath = $direct[0]['path'] ?? null;
        $dashboardId = ($dashboardPath ? ($hubIdByPath[$dashboardPath] ?? null) : null)
            ?? (array_values($hubIdByPath)[0] ?? null);

        if ($dashboardId === null) {
            // Nowhere to re-point anything, so the safe pass is to report and change nothing.
            $this->warn('  No hub or direct entry to re-point retired ribbons at; leaving them alone.');
            $count = DB::table('security_module_ribbons')->where('module_id', $moduleId)->count();

            return [$code, $before, $count, '0 (nothing to re-point)', 0];
        }

        $old = DB::table('security_module_ribbons')
            ->where('module_id', $moduleId)
            ->whereNotIn('ribbon_name', $keepNames)
            ->get(['id', 'ribbon_path']);

        $repointed = 0;
        $retired = 0;
        foreach ($old as $ribbon) {
            $hubPath = $cardToHub[$this->basePath($ribbon->ribbon_path)] ?? null;
            $targetId = ($hubPath && isset($hubIdByPath[$hubPath])) ? $hubIdByPath[$hubPath] : $dashboardId;

            $repointed += DB::table('module_how_tos')
                ->where('ribbon_id', $ribbon->id)
                ->update(['ribbon_id' => $targetId, 'updated_at' => now()]);

            if ($prune) {
                DB::table('security_module_ribbons')->where('id', $ribbon->id)->delete();
                $retired++;
            }
        }

        $after = DB::table('security_module_ribbons')->where('module_id', $moduleId)->count();

        return [$code, $before, $after, $prune ? $retired : $old->count() . ' (kept)', $repointed];
    }

    private function basePath(string $path): string
    {
        return trim(explode('?', $path, 2)[0], '/');
    }
}
