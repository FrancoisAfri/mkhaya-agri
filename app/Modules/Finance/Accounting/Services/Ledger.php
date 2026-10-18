<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinExchangeRate;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one shared double-entry posting engine. Operational modules never touch
 * fin_journals directly — they build a {@see FinancialEvent} and call post().
 * Every journal is cryptographically chained to the previous one (tamper-evident).
 */
class Ledger
{
    /** Genesis prev-hash for the first journal in a company's chain. */
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Post a balanced financial event as an immutable, hash-chained journal.
     * Idempotent: a second post with the same idempotency key returns the existing.
     */
    public function post(FinancialEvent $event): FinJournal
    {
        if (! $event->isBalanced()) {
            throw new PostingException("Refusing to post an unbalanced event ({$event->eventType}).");
        }

        $existing = FinJournal::where('idempotency_key', $event->idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $period = FinFiscalPeriod::forDate($event->eventDate, $event->companyId);
        if (! $period) {
            throw new PostingException("No fiscal period covers {$event->eventDate->toDateString()}.");
        }
        if (! $period->acceptsPostings()) {
            throw new PostingException("Period {$period->fiscal_year}/{$period->period_no} is {$period->status} — posting rejected.");
        }

        // Resolve accounts up front so the hash covers the exact persisted lines.
        $rows = [];
        foreach ($event->debits as $l) {
            $rows[] = $this->row($event, $l, 'debit');
        }
        foreach ($event->credits as $l) {
            $rows[] = $this->row($event, $l, 'credit');
        }

        return DB::transaction(function () use ($event, $period, $rows) {
            [$seq, $prevHash] = $this->lockChainHead($event->companyId);
            $hash = $this->computeHash($event->companyId, $event->eventDate, $event->currency, $rows, $prevHash);

            $journal = FinJournal::create([
                'uuid'            => (string) Str::uuid(),
                'seq'             => $seq,
                'company_id'      => $event->companyId,
                'journal_no'      => FinNumberSeries::next($event->companyId, 'journal', 'JNL-'),
                'journal_date'    => $event->eventDate,
                'period_id'       => $period->id,
                'source_module'   => $event->sourceModule,
                'source_ref'      => $event->sourceRef,
                'event_type'      => $event->eventType,
                'currency'        => $event->currency,
                'description'     => $event->description ?: null,
                'status'          => 'posted',
                'idempotency_key' => $event->idempotencyKey,
                'metadata'        => $event->metadata ?: null,
                'prev_hash'       => $prevHash,
                'hash'            => $hash,
                'posted_by'       => Auth::id(),
                'posted_at'       => now(),
            ]);

            foreach ($rows as $r) {
                FinJournalLine::create(['journal_id' => $journal->id] + $r);
            }

            return $journal;
        });
    }

    /**
     * Reverse a posted journal with a correcting (chained) entry. The original is
     * marked "reversed" and never otherwise altered.
     */
    public function reverse(FinJournal $journal, string $reason): FinJournal
    {
        if ($journal->status !== 'posted') {
            throw new PostingException('Only a posted journal can be reversed.');
        }

        $date   = now();
        $period = FinFiscalPeriod::forDate($date, $journal->company_id);
        if (! $period || ! $period->acceptsReversals()) {
            throw new PostingException('The current period does not accept reversals.');
        }

        // Swapped lines for the reversal.
        $rows = $journal->lines->map(fn ($line) => [
            'account_id' => $line->account_id, 'company_id' => $line->company_id,
            'debit' => $line->credit, 'credit' => $line->debit, 'currency' => $line->currency,
            'fx_rate' => $line->fx_rate, 'base_debit' => $line->base_credit, 'base_credit' => $line->base_debit,
            'narration' => 'Reversal', 'dimensions' => $line->dimensions,
        ])->all();

        return DB::transaction(function () use ($journal, $reason, $date, $period, $rows) {
            [$seq, $prevHash] = $this->lockChainHead($journal->company_id);
            $hash = $this->computeHash($journal->company_id, $date, $journal->currency, $rows, $prevHash);

            $reversal = FinJournal::create([
                'uuid'                => (string) Str::uuid(), 'seq' => $seq, 'company_id' => $journal->company_id,
                'journal_no'          => FinNumberSeries::next($journal->company_id, 'journal', 'JNL-'),
                'journal_date'        => $date, 'period_id' => $period->id,
                'source_module'       => $journal->source_module, 'source_ref' => $journal->source_ref,
                'event_type'          => 'reversal', 'currency' => $journal->currency,
                'description'         => "Reversal of {$journal->journal_no}: {$reason}",
                'status'              => 'posted', 'reverses_journal_id' => $journal->id,
                'idempotency_key'     => 'reversal:' . $journal->id,
                'prev_hash'           => $prevHash, 'hash' => $hash,
                'posted_by'           => Auth::id(), 'posted_at' => now(),
            ]);

            foreach ($rows as $r) {
                FinJournalLine::create(['journal_id' => $reversal->id] + $r);
            }

            $journal->status = 'reversed';
            $journal->save();

            return $reversal;
        });
    }

    /**
     * Recompute a journal's hash from its persisted lines (for verification/sealing).
     * Pass $prevHash to seal against a specific predecessor; otherwise the stored one.
     */
    public function recomputeHash(FinJournal $journal, ?string $prevHash = null): string
    {
        $rows = $journal->lines->map(fn ($l) => [
            'account_id' => $l->account_id, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit,
        ])->all();

        return $this->computeHash(
            $journal->company_id, $journal->journal_date, $journal->currency, $rows,
            $prevHash ?? (string) $journal->prev_hash
        );
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Serialise chain assignment per company; return [next seq, prev hash].
     *
     * The head is read across every business of the company, not just the one being posted in.
     *
     * The chain is one append-only sequence per company: `seq` counts up and each journal's
     * prev_hash is the previous one's hash. Reading the head through the entity filter would give
     * each business its own view of "the last journal", so Mkhaya MK and Mkhaya Move would both
     * claim seq 7 and both chain onto the same predecessor — two forks of one chain, with
     * duplicate sequence numbers. Nothing would fail at the time. It would surface later as
     * `finance:verify-chain` reporting the ledger as tampered with, and by then the only honest
     * answer about which journals are authentic is that nobody can tell.
     *
     * The advisory lock is already per company, for the same reason.
     */
    private function lockChainHead(?int $companyId): array
    {
        DB::selectOne('select pg_advisory_xact_lock(hashtext(?)::bigint)', ['fin_chain_' . ($companyId ?? 0)]);

        $last = FinJournal::withoutEntityScope()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->orderByDesc('seq')->first(['seq', 'hash']);

        return [($last->seq ?? 0) + 1, $last->hash ?? self::GENESIS];
    }

    private function computeHash(?int $companyId, $date, string $currency, array $rows, string $prevHash): string
    {
        $lines = collect($rows)
            ->map(fn ($r) => [
                (int) $r['account_id'],
                number_format((float) $r['debit'], 2, '.', ''),
                number_format((float) $r['credit'], 2, '.', ''),
            ])
            ->sortBy(fn ($r) => $r[0] . '|' . $r[1] . '|' . $r[2])->values()->all();

        $canonical = json_encode([$companyId, Carbon::parse($date)->toDateString(), $currency, $lines]);

        return hash('sha256', $canonical . $prevHash);
    }

    private function row(FinancialEvent $event, array $line, string $side): array
    {
        $account = $this->resolveAccount($line['account'], $event->companyId);
        $amount  = round((float) $line['amount'], 2);
        $rate    = $this->rateToBase($event->currency, $event->eventDate);

        return [
            'account_id'  => $account->id, 'company_id' => $event->companyId,
            'debit'       => $side === 'debit' ? $amount : 0,
            'credit'      => $side === 'credit' ? $amount : 0,
            'currency'    => $event->currency, 'fx_rate' => $rate,
            'base_debit'  => $side === 'debit' ? $amount * $rate : 0,
            'base_credit' => $side === 'credit' ? $amount * $rate : 0,
            'narration'   => $line['narration'] ?? null,
            'dimensions'  => $line['dimensions'] ?? null,
        ];
    }

    /** Convert the posting currency to the ledger's base currency for base_* columns. */
    private function rateToBase(string $currency, $date): float
    {
        $base = strtoupper((string) config('finance.base_currency', 'ZAR'));
        if (strtoupper($currency) === $base) {
            return 1.0;
        }

        $rate = FinExchangeRate::rateFor($currency, $base, $date);
        if ($rate === null) {
            throw new PostingException("No exchange rate for {$currency}->{$base} on " . Carbon::parse($date)->toDateString() . '.');
        }

        return $rate;
    }

    /**
     * The account to post against, preferring the company's own over the shared chart.
     *
     * The standard South African chart ships seeded with a null company — it is a library every
     * tenant reads, in the same way public holidays and contract templates are. Matching the
     * company strictly made those rows invisible the moment a posting named a company, so a
     * perfectly seeded installation reported "Account [1200] not found for company 1" the first
     * time somebody raised an invoice. The chart was there; nothing would look at it.
     *
     * The company's own row still wins where one exists, which is what makes a tenant able to
     * rename or re-purpose an account without that change reaching anybody else.
     */
    private function resolveAccount(string|int $ref, ?int $companyId): FinAccount
    {
        /*
         * An integer is an account id (it came off a model column); a string is a code ('1300').
         * Matching `id OR code` in one query let Postgres pick either row, so once the id sequence
         * passed 1270 the Inventory account (id 1270) lost to Staff Loans (code 1270). Each kind is
         * now tried on its own, the intended reading first, the other only as a fallback.
         */
        $attempts = is_int($ref)
            ? [fn ($q) => $q->where('id', $ref), fn ($q) => $q->where('code', (string) $ref)]
            : (is_numeric($ref)
                ? [fn ($q) => $q->where('code', $ref), fn ($q) => $q->where('id', (int) $ref)]
                : [fn ($q) => $q->where('code', $ref)]);

        $account = null;
        foreach ($attempts as $matches) {
            if ($companyId) {
                $account = FinAccount::query()->where('company_id', $companyId)->where($matches)->first()
                    // Falling back to the shared chart, which is what a fresh installation holds.
                    ?? FinAccount::query()->whereNull('company_id')->where($matches)->first();
            } else {
                // No company named — the standalone GL, where there is no tenant to be wrong about.
                $account = FinAccount::query()->where($matches)->first();
            }
            if ($account) {
                break;
            }
        }

        if (! $account) {
            throw new PostingException(
                "Account [{$ref}] not found" . ($companyId ? " for company {$companyId}" : '')
                . ', and no shared account with that code exists. Add it to the chart of accounts, '
                . 'or run `php artisan finance:ensure-chart` to install the standard one.'
            );
        }

        if (! $account->is_postable || ! $account->is_active) {
            throw new PostingException(
                "Account [{$account->code}] ({$account->name}) cannot be posted to: it is "
                . ($account->is_active ? 'a heading rather than a postable account' : 'inactive') . '.'
            );
        }

        return $account;
    }
}
