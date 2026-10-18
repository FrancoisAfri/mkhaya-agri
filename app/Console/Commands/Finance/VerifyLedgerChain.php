<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Services\Ledger;
use Illuminate\Console\Command;

/**
 * Walk each company's journal chain and prove it hasn't been tampered with:
 * every journal's stored hash must equal a fresh hash of its persisted lines +
 * its recorded prev_hash, and each prev_hash must equal the previous journal's
 * hash. Any edit to a historic journal (even direct in the DB) breaks this.
 */
class VerifyLedgerChain extends Command
{
    protected $signature = 'finance:verify-chain {--company= : Limit to one company_id}';
    protected $description = 'Verify the cryptographic integrity of the ledger (tamper detection)';

    public function handle(Ledger $ledger): int
    {
        $companies = FinJournal::withoutEntityScope()
            ->when($this->option('company'), fn ($q) => $q->where('company_id', $this->option('company')))
            ->select('company_id')->distinct()->pluck('company_id');

        if ($companies->isEmpty()) {
            $this->info('No journals to verify.');
            return self::SUCCESS;
        }

        $breaks = 0;
        $unreadable = [];

        /*
         * The company list is derived from the journals themselves — deliberately, and it must stay
         * that way. Switching to TenantContext's active-tenant list would skip the company-less
         * books (everything posted before Finance was tenanted, and every single-tenant install),
         * and a chain nobody verifies is exactly where tampering would sit.
         *
         * What it does share with the per-tenant crons is the guard: one set of books that cannot
         * be read must not stop the rest being verified.
         */
        foreach ($companies as $companyId) {
            try {
            $prevHash = str_repeat('0', 64);
            /*
             * Every journal of the company, whichever of its businesses issued it.
             *
             * The chain is one sequence per company: journal N's prev_hash is journal N-1's hash,
             * in `seq` order, across the whole company. Reading it through the entity filter would
             * hand back one business's journals with the other's missing from between them, and
             * every prev_hash after the first gap would fail to match — a chain that is perfectly
             * intact would be reported as tampered with.
             */
            $journals = FinJournal::withoutEntityScope()->with('lines')
                ->where(fn ($q) => $companyId === null ? $q->whereNull('company_id') : $q->where('company_id', $companyId))
                ->orderBy('seq')->get();

            $label = $companyId === null ? 'shared (no company)' : "company {$companyId}";
            $this->line("Chain for {$label}: {$journals->count()} journal(s)");

            foreach ($journals as $j) {
                $recomputed = $ledger->recomputeHash($j);
                $chainOk    = $j->prev_hash === $prevHash;
                $contentOk  = $j->hash === $recomputed;

                if (! $chainOk || ! $contentOk) {
                    $breaks++;
                    $reason = ! $contentOk ? 'content altered (hash mismatch)' : 'chain broken (prev_hash mismatch)';
                    $this->error("  ✗ {$j->journal_no} (seq {$j->seq}) — {$reason}");
                }

                $prevHash = $j->hash;
            }
            } catch (\Throwable $e) {
                $unreadable[] = $companyId === null ? 'shared' : $companyId;
                report($e);
                $this->error("  ✗ {$label}: could not be verified — {$e->getMessage()}");
            }
        }

        if ($unreadable !== []) {
            $this->error('Could not verify: '.implode(', ', $unreadable).'. The rest were checked.');
        }

        if ($breaks > 0 || $unreadable !== []) {
            $this->error("Integrity check FAILED — {$breaks} journal(s) fail verification.");
            return self::FAILURE;
        }

        $this->info('Integrity verified — every journal chain is intact.');
        return self::SUCCESS;
    }
}
