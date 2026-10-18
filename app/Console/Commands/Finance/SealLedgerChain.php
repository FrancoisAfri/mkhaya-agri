<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Services\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time (idempotent) seal for journals created before the hash-chain existed:
 * walks each company's journals in id order and assigns seq/prev_hash/hash.
 * Writes via the query builder to bypass the immutability guard — this is the
 * sanctioned system operation that establishes the chain, run once after deploy.
 */
class SealLedgerChain extends Command
{
    protected $signature = 'finance:seal-chain {--force : Re-seal even journals that already have a hash}';
    protected $description = 'Assign the cryptographic chain (seq/prev_hash/hash) to unsealed journals';

    public function handle(Ledger $ledger): int
    {
        // One chain per company, across all of its businesses — see the note in VerifyLedgerChain.
        $companies = FinJournal::withoutEntityScope()->select('company_id')->distinct()->pluck('company_id');
        $sealed = 0;

        foreach ($companies as $companyId) {
            $prevHash = str_repeat('0', 64);
            $seq = 0;

            $journals = FinJournal::withoutEntityScope()->with('lines')
                ->where(fn ($q) => $companyId === null ? $q->whereNull('company_id') : $q->where('company_id', $companyId))
                ->orderBy('id')->get();

            foreach ($journals as $j) {
                $seq++;

                if ($j->hash && ! $this->option('force')) {
                    $prevHash = $j->hash; // already sealed — keep walking the chain
                    continue;
                }

                $hash = $ledger->recomputeHash($j, $prevHash);
                DB::table('fin_journals')->where('id', $j->id)
                    ->update(['seq' => $seq, 'prev_hash' => $prevHash, 'hash' => $hash]);

                $prevHash = $hash;
                $sealed++;
            }
        }

        $this->info("Sealed {$sealed} journal(s). Run `finance:verify-chain` to confirm.");

        return self::SUCCESS;
    }
}
