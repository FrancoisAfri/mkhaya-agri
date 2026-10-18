<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\LedgerVerifier;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Rebuild every projection from the ledger and report any difference (WMS-212, §25.4, QA-027). */
class VerifyLedgerCommand extends Command
{
    protected $signature = 'wms:verify-ledger {--company=} {--json}';

    protected $description = 'Check stock balances, positions and on-hand against the stock ledger';

    public function handle(LedgerVerifier $verifier, WarehouseAudit $audit): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')]
            : DB::table('fin_stock_movements')->distinct()->whereNotNull('company_id')->pluck('company_id')->map(fn ($i) => (int) $i)->all();
        $summary = [];
        $all = [];
        foreach ($ids as $cid) {
            $r = $verifier->verify($cid);
            $summary[$cid] = count($r['problems']);
            $all[$cid] = $r;
            if (! $r['ok']) {
                $audit->record($cid, 'ledger.mismatch', 'LEDGER', null, count($r['problems']).' difference(s) between stock projections and the ledger', null, ['problems' => array_slice($r['problems'], 0, 50)]);
                foreach (array_slice($r['problems'], 0, 20) as $p) {
                    $this->warn("Company {$cid}: {$p['kind']} {$p['item']} ".($p['warehouse'] ?? '').' '.json_encode(array_diff_key($p, array_flip(['kind', 'item', 'item_id', 'warehouse', 'warehouse_id']))));
                }
            }
        }
        if (! $this->option('company')) {
            WarehouseHealth::finished('wms:verify-ledger', array_sum($summary) === 0, $summary);
        }
        $this->option('json') ? $this->line(json_encode($all)) : $this->info(count($ids).' compan(ies) checked; '.array_sum($summary).' difference(s).');

        return array_sum($summary) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
