<?php

namespace App\Modules\SupplierManagement\Console;

use App\Modules\SupplierManagement\DueDiligence\Services\EvidenceLedger;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Walks the due-diligence evidence chain and reports the first position where it does not reconcile.
 *
 * The hash chain only earns its keep if somebody checks it, so this is the command that makes it
 * meaningful — run it on a schedule, or before handing evidence to an auditor. A broken chain does
 * not prove tampering (a restored backup can do it too), but it does prove the ledger is no longer
 * self-consistent, which is exactly the moment to stop trusting it silently.
 *
 * Exits non-zero on a break so a scheduled run fails loudly instead of logging a clean pass.
 */
class VerifyEvidenceLedgerCommand extends Command
{
    protected $signature = 'suppliers:verify-evidence {--company= : Check one company only}';

    protected $description = 'Verify the supplier due-diligence evidence hash chain';

    public function handle(EvidenceLedger $ledger): int
    {
        $companyIds = $this->option('company')
            ? [(int) $this->option('company')]
            : DB::table('dd_evidence_entries')->distinct()->pluck('company_id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($companyIds === []) {
            $this->info('No evidence recorded yet — nothing to verify.');

            return self::SUCCESS;
        }

        $names = Company::query()->whereIn('id', $companyIds)->pluck('name', 'id');
        $broken = 0;
        $totalChecked = 0;

        foreach ($companyIds as $companyId) {
            $result = $ledger->verify($companyId);
            $label = $names[$companyId] ?? "company {$companyId}";
            $totalChecked += $result['checked'];

            if ($result['ok']) {
                $this->info("✓ {$label}: {$result['checked']} entr(ies) verified.");

                continue;
            }

            $broken++;
            $this->error("✗ {$label}: chain breaks at sequence {$result['broken_at']} — {$result['reason']}");
        }

        if ($broken > 0) {
            $this->newLine();
            $this->error("{$broken} ledger(s) do not reconcile. Treat the affected evidence as unverified until investigated.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("All ledgers reconcile ({$totalChecked} entr(ies) across ".count($companyIds).' company(ies)).');

        return self::SUCCESS;
    }
}
