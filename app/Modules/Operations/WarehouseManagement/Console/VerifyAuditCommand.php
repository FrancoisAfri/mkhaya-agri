<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Check every company's audit chain is unbroken (WMS-203); a break is reported and exits non-zero. */
class VerifyAuditCommand extends Command
{
    protected $signature = 'wms:verify-audit {--company=}';

    protected $description = 'Verify the warehouse audit ledger hash chain';

    public function handle(WarehouseAudit $audit): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')] : DB::table('wms_audit_events')->distinct()->pluck('company_id')->map(fn ($i) => (int) $i)->all();
        $bad = 0;
        foreach ($ids as $cid) {
            $r = $audit->verify($cid);
            if (! $r['ok']) {
                $bad++;
                $this->error("Company {$cid}: chain broken at sequence {$r['broken_at']} -- {$r['reason']}");
                report(new \RuntimeException("Warehouse audit chain broken for company {$cid} at {$r['broken_at']}"));
            }
        }
        $this->info(count($ids).' chain(s) checked, '.$bad.' broken.');

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
