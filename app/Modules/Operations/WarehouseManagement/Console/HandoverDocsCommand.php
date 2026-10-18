<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseAccounting;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseEvents;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseHealth;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseNotifier;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Operations\WarehouseManagement\Support\RolePresets;
use App\Modules\Operations\WarehouseManagement\Support\WarehousePolicySchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The generated parts of the developer handover (§34): read from the code and the database, so
 * they are true when generated and can be regenerated after any change. The narrative documents
 * beside them are written by hand.
 */
class HandoverDocsCommand extends Command
{
    protected $signature = 'wms:handover-docs {--dir=docs/warehouse/generated}';

    protected $description = 'Generate the data model, permission matrix, screens, API routes, events, accounting and schedule references';

    public function handle(): int
    {
        $dir = base_path($this->option('dir'));
        @mkdir($dir, 0775, true);
        $stamp = 'Generated '.now()->toDayDateTimeString()." by `php artisan wms:handover-docs` -- do not edit by hand; regenerate.\n\n";
        foreach (['data-model' => $this->dataModel(), 'permissions' => $this->permissions(), 'routes' => $this->routes(), 'events-and-alerts' => $this->events(),
            'accounting-events' => $this->accounting(), 'schedule-and-policies' => $this->schedule(), 'kpis' => $this->kpis()] as $name => $body) {
            file_put_contents("{$dir}/{$name}.md", preg_replace('/^(# .+\n\n)/', '$1'.$stamp, $body, 1));
            $this->line("Wrote {$this->option('dir')}/{$name}.md");
        }

        return self::SUCCESS;
    }

    private function dataModel(): string
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'public')->where('table_type', 'BASE TABLE')
            ->where(fn ($q) => $q->where('table_name', 'like', 'wms\_%')->orWhere('table_name', 'like', 'fin\_stock\_%')->orWhereIn('table_name', ['fin_warehouses']))
            ->orderBy('table_name')->pluck('table_name');
        $out = "# Data model\n\nEvery table the warehouse owns (`wms_*`) and the Finance stock tables it extends (`fin_stock_*`, `fin_warehouses`). "
            ."Finance's stock ledger is the only ledger; nothing here duplicates it. Columns, keys, constraints, triggers and row-level security are read from the database.\n\n"
            .'Tables: '.$tables->count().".\n\n";
        foreach ($tables as $t) {
            $cols = DB::select("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position", [$t]);
            $fks = DB::select("SELECT a.attname AS col, cf.relname AS ref, c.confdeltype AS del FROM pg_constraint c JOIN pg_class cl ON cl.oid = c.conrelid JOIN pg_class cf ON cf.oid = c.confrelid
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey) WHERE c.contype = 'f' AND cl.relname = ?", [$t]);
            $fk = collect($fks)->mapWithKeys(fn ($f) => [$f->col => "→ {$f->ref}".['c' => ' (cascade)', 'n' => ' (set null)', 'r' => ' (restrict)', 'a' => '', 'd' => ' (set default)'][$f->del]]);
            $idx = DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = ? AND indexdef ILIKE '%UNIQUE%'", [$t]);
            $checks = DB::select("SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c'", [$t]);
            $trig = DB::select("SELECT tgname FROM pg_trigger WHERE tgrelid = ?::regclass AND NOT tgisinternal", [$t]);
            $rls = DB::selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS force FROM pg_class WHERE relname = ?', [$t]);
            $out .= "## {$t}\n\n".($rls->on ? 'Row-level security: on'.($rls->force ? ', forced' : '').".\n\n" : "Row-level security: off.\n\n")
                ."| Column | Type | Null | Default / reference |\n|---|---|---|---|\n";
            foreach ($cols as $c) {
                $out .= "| {$c->column_name} | {$c->data_type} | ".($c->is_nullable === 'YES' ? 'yes' : 'no').' | '.trim(($fk[$c->column_name] ?? '').' '.Str::limit((string) $c->column_default, 40))." |\n";
            }
            foreach ($idx as $i) {
                $out .= "\n- Unique: `".preg_replace('/^.*USING btree /', '', $i->indexdef).'`';
            }
            foreach ($checks as $c) {
                $out .= "\n- Check `{$c->conname}`: `".Str::limit($c->def, 200).'`';
            }
            foreach ($trig as $tr) {
                $out .= "\n- Trigger: `{$tr->tgname}`";
            }
            $out .= "\n\n";
        }

        return $out;
    }

    private function permissions(): string
    {
        $ref = new \ReflectionClass(RoleCatalog::class);
        $out = "# Permission matrix\n\n## Permissions\n\n| Permission | Meaning |\n|---|---|\n";
        foreach ($ref->getReflectionConstants() as $c) {
            if (is_string($c->getValue()) && str_starts_with($c->getValue(), 'wms.')) {
                $doc = trim(preg_replace(['#/\*\*|\*/#', '#^\s*\*\s?#m'], '', (string) $c->getDocComment()));
                $out .= "| `{$c->getValue()}` | ".($doc !== '' ? str_replace("\n", ' ', $doc) : Str::headline(strtolower($c->getName())))." |\n";
            }
        }
        $presets = RolePresets::all();
        $out .= "\n## Role templates\n\nThe platform administrator and company administrator are the existing `super admin` and `administrator` roles, which hold every warehouse permission.\n\n"
            .'| Permission | '.implode(' | ', array_keys($presets))." |\n|---".str_repeat('|---', count($presets))."|\n";
        foreach (RoleCatalog::all() as $p) {
            $out .= "| `{$p}` | ".implode(' | ', array_map(fn ($perms) => in_array($p, $perms, true) ? '●' : '', $presets))." |\n";
        }
        $out .= "\n## Every route and the permission it checks\n\nRead from each controller action's source: the `RoleCatalog` permissions it checks and the policy abilities it authorises. "
            ."`viewAny` on stock items or warehouses means `wms.view`. Services refuse further (segregation of duties, approval levels, warehouse staff, client access) beyond what is listed.\n\n"
            ."| Method | Path | Checks |\n|---|---|---|\n";
        foreach ($this->warehouseRoutes() as $r) {
            $out .= "| {$r['method']} | `{$r['uri']}` | ".($r['checks'] ?: '_(none found -- verify)_')." |\n";
        }

        return $out;
    }

    private function routes(): string
    {
        $out = "# Screens and API routes\n\nEvery warehouse route: web screens and actions, then the versioned API. The contract for the API is `resources/api/warehouse-v1.yaml`.\n\n"
            ."| Method | Path | Name | Controller action |\n|---|---|---|---|\n";
        foreach ($this->warehouseRoutes() as $r) {
            $out .= "| {$r['method']} | `{$r['uri']}` | {$r['name']} | {$r['action']} |\n";
        }

        return $out;
    }

    private function warehouseRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! Str::startsWith($name, ['warehouse.', 'api.warehouse.'])) {
                continue;
            }
            $action = $route->getActionName();
            $checks = [];
            $src = null;
            if (str_contains($action, '@')) {
                [$class, $method] = explode('@', $action);
                if (method_exists($class, $method)) {
                    $m = new \ReflectionMethod($class, $method);
                    $src = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
                    preg_match_all('/RoleCatalog::([A-Z_]+)/', $src, $perms);
                    preg_match_all("/authorize\('([a-zA-Z]+)'/", $src, $abilities);
                    $checks = array_merge(array_map(fn ($c) => '`'.constant(RoleCatalog::class.'::'.$c).'`', array_unique($perms[1])), array_map(fn ($a) => "policy:{$a}", array_unique($abilities[1])));
                }
            }
            if (Str::startsWith($name, 'warehouse.hub.')) {
                $checks = ['module tier; each card has its own gate'];
            } elseif ($name === 'api.warehouse.openapi') {
                $checks = ['public by design: the contract only, no data'];
            } elseif (isset($src) && str_contains($src, 'mayDecide')) {
                $checks[] = 'whoever may decide the current approval level (own permission or delegation; never the requester)';
            }
            $out[] = ['method' => implode('|', array_diff($route->methods(), ['HEAD'])), 'uri' => '/'.$route->uri(), 'name' => $name,
                'action' => class_basename(Str::before($action, '@')).'@'.Str::after($action, '@'), 'checks' => implode(', ', $checks)];
        }
        usort($out, fn ($a, $b) => strcmp($a['uri'], $b['uri']));

        return $out;
    }

    private function events(): string
    {
        $out = "# Events and alerts\n\n## Published events (outbox, API feed, webhooks)\n\n| Type | Version | Meaning | Fields |\n|---|---|---|---|\n";
        foreach (WarehouseEvents::TYPES as $type => [$v, $text, $fields]) {
            $out .= "| `{$type}` | {$v} | {$text} | ".implode(', ', $fields)." |\n";
        }
        $out .= "\n## Alerts\n\n| Alert | Told by default | Thresholds | Escalates after | To |\n|---|---|---|---|---|\n";
        foreach (WarehouseNotifier::EVENTS as $e => [$perms, $th, $after, $to]) {
            $out .= "| {$e} | ".(implode(', ', $perms) ?: 'whoever may decide')." | ".(json_encode($th) ?: '').' | '.($after ? "{$after} h" : '—').' | '.implode(', ', $to ?? [])." |\n";
        }

        return $out;
    }

    private function accounting(): string
    {
        $out = "# Accounting events\n\nThe default treatment of every warehouse event. Each company's rules are in `wms_accounting_rules` and may be changed (and suspended) by people with `wms.accounting.approve`; "
            ."account roles resolve through Finance's account map (module `warehouse`).\n\n| Event | Owner | Treatment | Debit | Credit | In force by default | What happens |\n|---|---|---|---|---|---|---|\n";
        foreach (WarehouseAccounting::EVENTS as $e => [$owner, $treat, $dr, $cr, $on, $text]) {
            $out .= "| {$e} | {$owner} | {$treat} | ".($dr ?? '—').' | '.($cr ?? '—').' | '.($on ? 'yes' : 'no, needs approval')." | {$text} |\n";
        }

        return $out;
    }

    private function kpis(): string
    {
        $out = "# KPI definitions\n\nDefinition version ".\App\Modules\Operations\WarehouseManagement\Services\WarehouseKpis::VERSION.'. Figures are kept per month with this version (`wms_kpi_snapshots`); '
            ."a changed definition gets a new version and is not compared with the old.\n\n| KPI | Unit | Better | Definition | Excludes |\n|---|---|---|---|---|\n";
        foreach (\App\Modules\Operations\WarehouseManagement\Services\WarehouseKpis::DEFINITIONS as [$name, $unit, $higher, $def, $ex]) {
            $out .= "| {$name} | {$unit} | ".($higher ? 'higher' : 'lower')." | {$def} | {$ex} |\n";
        }

        return $out;
    }

    private function schedule(): string
    {
        $out = "# Scheduled work and policies\n\n## Scheduled commands\n\n| Command | Expected every | What it does |\n|---|---|---|\n";
        foreach (WarehouseHealth::SCHEDULE as $cmd => $every) {
            $class = collect(\Illuminate\Support\Facades\Artisan::all())->get($cmd);
            $out .= "| `{$cmd}` | ".($every >= 1440 ? round($every / 1440).' day(s)' : "{$every} min").' | '.($class?->getDescription() ?? '')." |\n";
        }
        $out .= "\n## Warehouse policies\n\n| Policy | Kind | Allowed | Default |\n|---|---|---|---|\n";
        foreach (WarehousePolicySchema::RULES as $k => $r) {
            $allowed = match ($r[0]) { 'enum' => implode(', ', $r[1]), 'int', 'number' => "{$r[1]} to {$r[2]}".(($r[3] ?? false) ? ' or empty' : ''), 'days_list' => "list of days {$r[1]}–{$r[2]}", 'gln' => '13-digit GLN', default => 'on / off' };
            $out .= "| `{$k}` | {$r[0]} | {$allowed} | ".json_encode(WarehousePolicySchema::defaults()[$k] ?? null)." |\n";
        }

        return $out;
    }
}
