<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give the due-diligence add-on a way into itself.
 *
 * Migration 470012 registered the module so `module.active` would stop bouncing its screens to the
 * dashboard, but registered it with no ribbons — so the add-on existed, was entitled, was reachable
 * by URL, and appeared nowhere in the navigation. The only routes in were a card at the end of the
 * Suppliers hub and a second one two clicks into Configure, which is why somebody scanning the
 * sidebar for what they had bought concluded nothing had been installed.
 *
 * The ribbons go on the add-on's OWN module row rather than Supplier Management's, and that choice
 * is the whole point: navigation is filtered by `CompanyEntitlements::allowsNavModule()`, so a
 * tenant without the add-on never sees these entries. Hanging them off Supplier Management would
 * have shown them to everybody and led the unentitled straight into a redirect.
 *
 * Access is mirrored from Supplier Management because this is an extension of that module, not a
 * new privilege: the due-diligence panels already render on the supplier page for anyone holding
 * `suppliers.view`, and every route behind these ribbons still enforces `suppliers.view` or
 * `suppliers.update` on its own. Mirroring makes the add-on visible to exactly the people who can
 * already see the data it presents — it does not widen who can see it.
 */
return new class extends Migration
{
    private const MODULE_CODE = 'supplier_due_diligence';

    private const RIBBONS = [
        ['name' => 'Monitoring',   'path' => 'suppliers/monitoring',            'icon' => 'radar',    'sort' => 1],
        ['name' => 'Data Sources', 'path' => 'suppliers/settings/data-sources', 'icon' => 'database', 'sort' => 2],
    ];

    public function up(): void
    {
        $module = DB::table('security_modules')->where('code_name', self::MODULE_CODE)->first();

        if (! $module) {
            return;
        }

        foreach (self::RIBBONS as $ribbon) {
            $exists = DB::table('security_module_ribbons')
                ->where('module_id', $module->id)
                ->where('ribbon_path', $ribbon['path'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('security_module_ribbons')->insert([
                'uuid' => (string) Str::uuid(),
                'module_id' => $module->id,
                'ribbon_name' => $ribbon['name'],
                'ribbon_path' => $ribbon['path'],
                'description' => $ribbon['name'],
                'font_awesome' => $ribbon['icon'],
                'sort_order' => $ribbon['sort'],
                'access_level' => 1,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $supplierModule = DB::table('security_modules')->where('code_name', 'supplier_management')->first();

        if (! $supplierModule) {
            return;
        }

        // Mirror, never downgrade: a user who already has a row for the add-on keeps whatever
        // level an administrator gave them.
        $alreadyGranted = DB::table('security_module_access')
            ->where('module_id', $module->id)
            ->pluck('user_id')
            ->all();

        $rows = DB::table('security_module_access')
            ->where('module_id', $supplierModule->id)
            ->whereNotIn('user_id', $alreadyGranted)
            ->get(['user_id', 'access_level', 'active'])
            ->map(fn ($row) => [
                'module_id' => $module->id,
                'user_id' => $row->user_id,
                'access_level' => $row->access_level,
                'active' => $row->active,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('security_module_access')->insert($chunk);
        }
    }

    public function down(): void
    {
        $module = DB::table('security_modules')->where('code_name', self::MODULE_CODE)->first();

        if (! $module) {
            return;
        }

        DB::table('security_module_ribbons')
            ->where('module_id', $module->id)
            ->whereIn('ribbon_path', array_column(self::RIBBONS, 'path'))
            ->delete();

        // Deliberately removes every access row, not only the mirrored ones: a module left with
        // grants but no ribbons still renders as an empty entry in the sidebar, which is a worse
        // state to roll back to than a clean absence. The cost is that a grant an administrator
        // added by hand after this migration ran is lost on rollback.
        DB::table('security_module_access')->where('module_id', $module->id)->delete();
    }
};
