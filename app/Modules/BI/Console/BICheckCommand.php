<?php

namespace App\Modules\BI\Console;

use App\Modules\BI\Services\BIEngine;
use App\Modules\BI\Support\DatasetRegistry;
use App\Modules\BI\Support\TemplateRegistry;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves every dataset and every template against the real database: each measure and dimension
 * is queried, and every template widget is run through the engine. A template that names a
 * dataset, measure, dimension or visual that does not exist fails here, not on somebody's screen.
 *
 *   php artisan bi:check                 all datasets and templates
 *   php artisan bi:check --file=hr       only Datasets/hr.php and Templates/hr.php
 */
class BICheckCommand extends Command
{
    protected $signature = 'bi:check {--file= : dataset/template file name without .php} {--company= : company id to run as}';

    protected $description = 'Validate BI datasets and dashboard templates against the database';

    public function handle(BIEngine $engine): int
    {
        $company = (int) ($this->option('company') ?: DB::table('companies')->orderBy('id')->value('id'));
        app(TenantContext::class)->apply($company);
        DB::statement("select set_config('app.company_id', ?, false)", [(string) $company]);
        $only = $this->option('file');
        $fails = 0;

        $datasets = DatasetRegistry::all();
        foreach ($datasets as $key => $ds) {
            if ($only && ! $this->inFile('Datasets', $only, $key)) {
                continue;
            }
            foreach (['module', 'label', 'from', 'company', 'date'] as $req) {
                if (empty($ds[$req])) {
                    $this->error("{$key}: missing '{$req}'");
                    $fails++;
                }
            }
            if (! DB::table('security_modules')->where('code_name', $ds['module'])->exists() && ! DB::table('billing_modules')->where('code', $ds['module'])->exists()) {
                $this->warn("{$key}: module '{$ds['module']}' is not a known module code");
            }
            $where = $ds['where'] ? " and ({$ds['where']})" : '';
            foreach ($ds['measures'] as $m => $def) {
                if (isset($def['ratio'])) {
                    foreach ($def['ratio'] as $part) {
                        if (! isset($ds['measures'][$part]['sql'])) {
                            $this->error("{$key}.{$m}: ratio part '{$part}' is not a SQL measure");
                            $fails++;
                        }
                    }
                    continue;
                }
                $fails += $this->try("{$key}.{$m}", "select {$def['sql']} from {$ds['from']} where {$ds['company']} = ? and {$ds['date']} >= '2000-01-01'{$where}", [$company]);
            }
            foreach ($ds['dimensions'] as $d => $def) {
                $fails += $this->try("{$key}.dim:{$d}", "select {$def['sql']} as v, count(*) from {$ds['from']} where {$ds['company']} = ?{$where} group by 1 limit 3", [$company]);
            }
            $this->line("  dataset {$key}: " . count($ds['measures']) . ' measures, ' . count($ds['dimensions']) . ' dimensions');
        }

        $templates = TemplateRegistry::all();
        foreach ($templates as $key => $t) {
            if ($only && ! $this->inFile('Templates', $only, $key)) {
                continue;
            }
            foreach ($t['widgets'] as $w) {
                $kind = BIEngine::TYPE_KIND[$w['type']] ?? null;
                $label = "template {$key} / " . ($w['title'] ?? $w['type']);
                if (! $kind) {
                    $this->error("{$label}: unknown visual type '{$w['type']}'");
                    $fails++;
                    continue;
                }
                if ($kind === 'none') {
                    continue;
                }
                $cfg = $w['config'] + ['kind' => $kind];
                $ds = DatasetRegistry::get($cfg['dataset'] ?? '');
                if (! $ds) {
                    $this->error("{$label}: unknown dataset '" . ($cfg['dataset'] ?? '') . "'");
                    $fails++;
                    continue;
                }
                foreach ((array) ($cfg['measures'] ?? []) as $m) {
                    if (! isset($ds['measures'][$m]) && ! isset(DatasetRegistry::COMPARE[$m])) {
                        $this->error("{$label}: dataset {$cfg['dataset']} has no measure '{$m}'");
                        $fails++;
                    }
                }
                foreach (['dimension', 'series'] as $d) {
                    if (! empty($cfg[$d]) && $cfg[$d] !== 'month' && ! isset($ds['dimensions'][$cfg[$d]])) {
                        $this->error("{$label}: dataset {$cfg['dataset']} has no dimension '{$cfg[$d]}'");
                        $fails++;
                    }
                }
                try {
                    $engine->run($cfg, ['from' => now()->subYear()->toDateString(), 'to' => now()->toDateString()], null, false);
                } catch (\Throwable $e) {
                    $this->error("{$label}: " . $e->getMessage());
                    $fails++;
                }
            }
            $this->line("  template {$key}: " . count($t['widgets']) . ' widgets (' . implode(', ', $t['modules']) . ')');
        }

        $this->newLine();
        $this->info(count($datasets) . ' datasets, ' . count($templates) . ' templates in total.');
        if ($fails) {
            $this->error("{$fails} problem(s).");

            return self::FAILURE;
        }
        $this->info('All good.');

        return self::SUCCESS;
    }

    private function try(string $label, string $sql, array $bind): int
    {
        try {
            DB::select($sql, $bind);

            return 0;
        } catch (\Throwable $e) {
            $this->error("{$label}: " . strtok($e->getMessage(), "\n"));

            return 1;
        }
    }

    /** Is this key defined in Datasets/{file}.php (or Templates/{file}.php)? */
    private function inFile(string $dir, string $file, string $key): bool
    {
        $path = app_path("Modules/BI/{$dir}/{$file}.php");

        return is_file($path) && array_key_exists($key, (array) require $path);
    }
}
