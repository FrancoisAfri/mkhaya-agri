<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\BarcodeRegistry;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LedgerVerifier;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\StockAvailability;
use App\Modules\Operations\WarehouseManagement\Services\TaskBoard;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseDashboards;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseReports;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Performance and concurrency measurement (WMS-210, WMS-211, QA-028).
 *
 * Builds a benchmark company with a representative catalogue and stock, times the operations the
 * service-level targets name, then runs concurrent workers that allocate and receive the same hot
 * items at once, and finally checks the ledger is still whole. Numbers are only meaningful on the
 * deployment architecture; the command records the environment with them so they are not misread.
 *
 * Never in production: it writes a whole company of data, which -- like any ledger -- cannot be
 * deleted afterwards. Run it on a disposable staging copy.
 */
class BenchmarkCommand extends Command
{
    protected $signature = 'wms:benchmark {--items=2000} {--warehouses=3} {--bins=200} {--repeat=200} {--workers=8} {--ops=25}
        {--doc= : Write a markdown summary to this path} {--force : Run outside local/testing}
        {--worker= : Internal: run as a concurrency worker} {--company=} {--warehouse=} {--hot=}';

    protected $description = 'Measure warehouse operation latency and concurrent stock integrity';

    public function handle(): int
    {
        if ($this->option('worker')) {
            return $this->worker();
        }
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refused: the benchmark writes a whole company of data. Run it on a staging copy with --force.');

            return self::FAILURE;
        }
        $started = microtime(true);
        $company = Company::create(['name' => 'WMS benchmark '.now()->format('Ymd His')]);
        app(CurrentCompany::class)->set($company->id);
        $this->call('finance:ensure-chart', ['--company' => $company->id, '--install' => true]);
        if (! \App\Modules\Finance\Accounting\Models\FinFiscalPeriod::forDate(now(), null)) {
            \App\Modules\Finance\Accounting\Models\FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
        $user = User::factory()->create(['email' => 'wms-bench-'.Str::random(6).'@example.test', 'status' => 1, 'company_id' => $company->id]);
        $user->givePermissionTo(\App\Modules\Operations\WarehouseManagement\Support\RoleCatalog::all());
        auth()->setUser($user);

        $this->info('Building the catalogue and stock…');
        [$warehouses, $items, $barcodes] = $this->seed($company, (int) $this->option('items'), (int) $this->option('warehouses'), (int) $this->option('bins'));
        $seedSeconds = round(microtime(true) - $started, 1);

        $r = (int) $this->option('repeat');
        $pick = fn ($a) => $a[array_rand($a)];
        $results = [];
        $results['stock_lookup'] = $this->time($r, fn () => app(StockAvailability::class)->forItem($pick($items)));
        $results['barcode_scan'] = $this->time($r, fn () => app(BarcodeRegistry::class)->resolve($pick($barcodes)));
        $results['receipt_posting'] = $this->time($r, function () use ($items, $warehouses, $pick) {
            $w = $pick($warehouses);
            app(StockLedger::class)->receive($pick($items)->fresh(), 5, 10, $w, ['bin_id' => $this->binOf($w)]);
        });
        $results['bin_move'] = $this->time((int) ceil($r / 2), function () use ($warehouses, $pick, $user) {
            $w = $pick($warehouses);
            $p = DB::table('fin_stock_positions')->where('warehouse_id', $w->id)->where('qty', '>=', 1)->where('status', 'AVAILABLE')->whereNotNull('bin_id')->inRandomOrder()->first();
            $to = FinStockBin::query()->where('warehouse_id', $w->id)->where('level', 'BIN')->where('id', '<>', $p->bin_id)->inRandomOrder()->first();
            app(\App\Modules\Operations\WarehouseManagement\Services\BinTransfers::class)->move($w, FinStockItem::query()->find($p->item_id), FinStockBin::query()->find($p->bin_id), $to, 1, $p->lot_id, 'AVAILABLE', $user);
        });
        $results['allocation'] = $this->time((int) ceil($r / 2), function () use ($warehouses, $items, $pick, $user) {
            $w = $pick($warehouses);
            [$o] = app(FulfilmentOrders::class)->create($w, 'API', 'BENCH-'.Str::random(10), [['item_id' => $pick($items)->id, 'qty' => 1]], [], $user);
            app(Allocator::class)->allocate(app(FulfilmentOrders::class)->release($o));
        });
        $results['task_queue'] = $this->time((int) ceil($r / 4), fn () => app(TaskBoard::class)->queue($pick($warehouses)));
        $results['inventory_dashboard'] = $this->time(10, fn () => app(WarehouseDashboards::class)->inventory([], true));
        $results['report_stock_on_hand'] = $this->time(3, fn () => app(WarehouseReports::class)->rows('stock_on_hand', [], true));

        $this->info('Concurrent allocation and receiving…');
        $results['concurrency'] = $this->concurrency($company, $warehouses[0], array_slice($items, 0, 5));
        $ledger = app(LedgerVerifier::class)->verify($company->id);

        $report = ['generated_at' => now()->toIso8601String(), 'environment' => ['app_env' => app()->environment(), 'php' => PHP_VERSION,
            'database' => DB::selectOne('select version() as v')->v, 'cpu' => trim((string) @shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null || nproc 2>/dev/null')),
            'queue' => config('queue.default')],
            'volumes' => ['items' => count($items), 'warehouses' => count($warehouses), 'bins' => (int) $this->option('bins') * count($warehouses),
                'positions' => DB::table('fin_stock_positions')->where('company_id', $company->id)->count(), 'movements' => DB::table('fin_stock_movements')->where('company_id', $company->id)->count()],
            'seed_seconds' => $seedSeconds, 'results' => $results, 'ledger' => ['ok' => $ledger['ok'], 'problems' => array_slice($ledger['problems'], 0, 20)],
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1)];
        $this->table(['Operation', 'n', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms', 'per s', 'errors'], collect($results)->except('concurrency')
            ->map(fn ($x, $k) => [$k, $x['n'], $x['p50'], $x['p95'], $x['p99'], $x['max'], $x['per_second'], $x['errors']])->values()->all());
        $c = $results['concurrency'];
        $this->line("Concurrency: {$c['workers']} workers × {$c['ops_each']} ops, {$c['ops']} done, {$c['errors']} error(s), {$c['refusals']} polite refusal(s), p95 {$c['p95']} ms, deadlocks {$c['deadlocks']}; ledger ".($ledger['ok'] ? 'whole' : 'NOT whole'));
        $path = storage_path('app/wms-benchmark-'.now()->format('Ymd-His').'.json');
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));
        $this->info("Saved {$path}");
        if ($doc = $this->option('doc')) {
            file_put_contents(base_path($doc), $this->markdown($report));
            $this->info("Wrote {$doc}");
        }
        // Ledgers cannot be deleted (fin_stock_movements is immutable by design), so neither can this company.
        $this->warn("Benchmark company #{$company->id} stays in this database. Run the benchmark on a disposable staging copy.");

        return $ledger['ok'] && $c['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function seed(Company $company, int $nItems, int $nWh, int $nBins): array
    {
        $cat = app(ItemCatalogue::class);
        $cat->ensureUnits($company->id);
        $warehouses = [];
        for ($w = 1; $w <= $nWh; $w++) {
            $wh = FinWarehouse::create(['company_id' => $company->id, 'code' => "BW{$w}", 'name' => "Bench {$w}", 'is_active' => true, 'is_wms_managed' => true,
                'policies' => ['scan_to_confirm' => false]]);
            $zone = app(LocationService::class)->create($wh, ['code' => "BW{$w}-Z", 'level' => 'ZONE']);
            for ($b = 1; $b <= $nBins; $b++) {
                app(LocationService::class)->create($wh, ['code' => "BW{$w}-".str_pad((string) $b, 4, '0', STR_PAD_LEFT), 'parent_id' => $zone->id, 'pick_sequence' => $b]);
            }
            $warehouses[] = $wh;
        }
        $items = [];
        $barcodes = [];
        $bar = $this->output->createProgressBar($nItems);
        for ($i = 1; $i <= $nItems; $i++) {
            $item = $cat->create(['company_id' => $company->id, 'code' => 'B'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'name' => "Bench item {$i}", 'uom' => 'ea', 'standard_cost' => 10]);
            if ($i % 4 === 0) {
                $barcodes[] = app(BarcodeRegistry::class)->generateInternal($item)->barcode;
            }
            foreach ($warehouses as $wh) {
                app(StockLedger::class)->receive($item->fresh(), 50, 10, $wh, ['bin_id' => $this->binOf($wh)]);
            }
            $items[] = $item;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        return [$warehouses, $items, $barcodes ?: [$items[0]->code]];
    }

    private function binOf(FinWarehouse $w): int
    {
        static $bins = [];
        $bins[$w->id] ??= FinStockBin::query()->where('warehouse_id', $w->id)->where('level', 'BIN')->pluck('id')->all();

        return $bins[$w->id][array_rand($bins[$w->id])];
    }

    /** @return array{n:int,p50:float,p95:float,p99:float,max:float,per_second:float,errors:int} */
    private function time(int $n, callable $op): array
    {
        $t = [];
        $errors = 0;
        $start = microtime(true);
        for ($i = 0; $i < $n; $i++) {
            $s = microtime(true);
            try {
                $op();
            } catch (\Throwable $e) {
                $errors++;
                if ($errors === 1) {
                    $this->warn(mb_substr($e->getMessage(), 0, 200));
                }
            }
            $t[] = (microtime(true) - $s) * 1000;
        }

        return $this->stats($t, $errors, microtime(true) - $start);
    }

    private function stats(array $t, int $errors, float $seconds): array
    {
        sort($t);
        $q = fn ($p) => $t ? round($t[min(count($t) - 1, (int) floor($p * count($t)))], 1) : 0.0;

        return ['n' => count($t), 'p50' => $q(0.5), 'p95' => $q(0.95), 'p99' => $q(0.99), 'max' => $t ? round(end($t), 1) : 0.0,
            'per_second' => $seconds > 0 ? round(count($t) / $seconds, 1) : 0.0, 'errors' => $errors];
    }

    private function concurrency(Company $company, FinWarehouse $w, array $hot): array
    {
        $deadlocks = fn () => (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
        $before = $deadlocks();
        $workers = (int) $this->option('workers');
        $procs = [];
        $start = microtime(true);
        for ($i = 0; $i < $workers; $i++) {
            $p = new Process([PHP_BINARY, base_path('artisan'), 'wms:benchmark', '--worker='.($i % 2 ? 'receive' : 'allocate'), '--company='.$company->id,
                '--warehouse='.$w->id, '--hot='.implode(',', array_map(fn ($x) => $x->id, $hot)), '--ops='.$this->option('ops')], base_path(), null, null, 600);
            $p->start();
            $procs[] = $p;
        }
        $t = [];
        $errors = 0;
        $refusals = 0;
        foreach ($procs as $p) {
            $p->wait();
            $out = json_decode(trim(collect(explode("\n", trim($p->getOutput())))->last() ?? ''), true);
            if (! is_array($out)) {
                $errors++;
                $this->warn('A worker failed: '.mb_substr($p->getErrorOutput().$p->getOutput(), 0, 300));

                continue;
            }
            $t = array_merge($t, $out['t']);
            $errors += $out['errors'];
            $refusals += $out['refusals'];
        }

        return ['workers' => $workers, 'ops_each' => (int) $this->option('ops'), 'ops' => count($t), 'refusals' => $refusals,
            'deadlocks' => $deadlocks() - $before] + $this->stats($t, $errors, microtime(true) - $start);
    }

    /** One concurrent worker: allocates or receives the same hot items as the others, and reports its timings. */
    private function worker(): int
    {
        app(CurrentCompany::class)->set((int) $this->option('company'));
        $w = FinWarehouse::query()->findOrFail((int) $this->option('warehouse'));
        $hot = FinStockItem::query()->whereIn('id', explode(',', (string) $this->option('hot')))->get()->all();
        $user = User::query()->withoutGlobalScopes()->where('company_id', $w->company_id)->firstOrFail();
        auth()->setUser($user);
        $t = [];
        $errors = 0;
        $refusals = 0;
        for ($i = 0; $i < (int) $this->option('ops'); $i++) {
            $item = $hot[array_rand($hot)];
            $s = microtime(true);
            try {
                if ($this->option('worker') === 'receive') {
                    app(StockLedger::class)->receive($item->fresh(), 1, 10, $w, ['bin_id' => $this->binOf($w)]);
                } else {
                    [$o] = app(FulfilmentOrders::class)->create($w, 'API', 'CON-'.Str::random(12), [['item_id' => $item->id, 'qty' => 7]], [], $user);
                    app(Allocator::class)->allocate(app(FulfilmentOrders::class)->release($o));
                }
            } catch (\RuntimeException $e) {
                // A refusal (nothing left to promise) is the system working, not an error.
                $e instanceof \Illuminate\Database\QueryException ? $errors++ : $refusals++;
            } catch (\Throwable) {
                $errors++;
            }
            $t[] = (microtime(true) - $s) * 1000;
        }
        $this->line(json_encode(['t' => $t, 'errors' => $errors, 'refusals' => $refusals]));

        return self::SUCCESS;
    }

    private function markdown(array $r): string
    {
        $rows = collect($r['results'])->except('concurrency')->map(fn ($x, $k) => "| {$k} | {$x['n']} | {$x['p50']} | {$x['p95']} | {$x['p99']} | {$x['max']} | {$x['per_second']} | {$x['errors']} |")->implode("\n");
        $c = $r['results']['concurrency'];
        $e = $r['environment'];
        $v = $r['volumes'];

        return "# Warehouse benchmark results\n\nGenerated {$r['generated_at']} by `php artisan wms:benchmark`. **These figures describe the machine below, not production.** "
            ."Targets (WMS-210) are validated only on the deployment architecture.\n\n"
            ."Environment: {$e['app_env']}, PHP {$e['php']}, {$e['database']}, CPU {$e['cpu']}, queue {$e['queue']}.\n\n"
            ."Volumes: {$v['items']} items, {$v['warehouses']} warehouses, {$v['bins']} bins, {$v['positions']} positions, {$v['movements']} movements (seeded in {$r['seed_seconds']} s).\n\n"
            ."| Operation | n | p50 ms | p95 ms | p99 ms | max ms | per second | errors |\n|---|---|---|---|---|---|---|---|\n{$rows}\n\n"
            ."Concurrency: {$c['workers']} processes × {$c['ops_each']} operations on the same 5 items (half allocating 7 units per order, half receiving): "
            ."{$c['ops']} operations, p50 {$c['p50']} ms, p95 {$c['p95']} ms, p99 {$c['p99']} ms, {$c['errors']} error(s), {$c['deadlocks']} deadlock(s), {$c['refusals']} refusal(s). An order short of stock waits as a backorder rather than failing; the ledger check below (which includes over-reservation) is what proves no unit was promised twice.\n\n"
            .'Ledger after the run: '.($r['ledger']['ok'] ? 'balances, positions, on-hand and reservations all agree with the ledger.' : 'DIFFERENCES FOUND: '.json_encode($r['ledger']['problems']))."\n\n"
            ."Peak memory {$r['peak_memory_mb']} MB.\n";
    }
}
