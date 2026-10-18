<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinBankMatch;
use App\Modules\Finance\Accounting\Models\FinBankRule;
use App\Modules\Finance\Accounting\Models\FinBankStatementLine;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Bank reconciliation: matches imported statement lines against the book entries
 * (journal lines posted to the bank's CoA account). Matches live in their own
 * table, so journal lines stay immutable. Auto-match uses amount + a date window.
 */
class ReconciliationService
{
    public function __construct(private Ledger $ledger)
    {
    }

    /** Unreconciled book entries (journal lines on the bank account, not yet matched). */
    public function bookEntries(FinBankAccount $bank)
    {
        return FinJournalLine::query()
            ->join('fin_journals as j', 'j.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journal_lines.account_id', $bank->account_id)
            ->where('j.status', '!=', 'draft')
            ->whereNotIn('fin_journal_lines.id', fn ($q) => $q->select('journal_line_id')->from('fin_bank_matches'))
            ->orderBy('j.journal_date')->orderBy('fin_journal_lines.id')
            ->get([
                'fin_journal_lines.id', 'fin_journal_lines.debit', 'fin_journal_lines.credit',
                'fin_journal_lines.narration', 'j.journal_no', 'j.journal_date', 'j.description as journal_desc',
            ])
            ->map(fn ($l) => [
                'id' => $l->id, 'journal_no' => $l->journal_no, 'date' => Carbon::parse($l->journal_date)->toDateString(),
                'description' => $l->journal_desc ?: $l->narration,
                'amount' => round((float) $l->debit - (float) $l->credit, 2), // + inflow, − outflow
            ]);
    }

    public function statementLines(FinBankAccount $bank, string $status = 'unmatched')
    {
        return $bank->statementLines()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('statement_date')->orderBy('id')->get()
            ->map(fn (FinBankStatementLine $s) => [
                'id' => $s->id, 'date' => $s->statement_date->toDateString(), 'description' => $s->description,
                'reference' => $s->reference, 'amount' => (float) $s->amount, 'status' => $s->status,
            ]);
    }

    /** Auto-match unmatched statement lines to a unique book entry (amount + date window). */
    public function autoMatch(FinBankAccount $bank, int $windowDays = 4): int
    {
        $book = collect($this->bookEntries($bank));
        $matched = 0;

        foreach ($bank->statementLines()->where('status', 'unmatched')->get() as $line) {
            $date = Carbon::parse($line->statement_date);
            $candidates = $book->filter(fn ($b) => abs($b['amount'] - (float) $line->amount) < 0.01
                && abs(Carbon::parse($b['date'])->diffInDays($date, false)) <= $windowDays);

            if ($candidates->count() === 1) {
                $entry = $candidates->first();
                $this->createMatch($line, (int) $entry['id'], (float) $line->amount);
                $book = $book->reject(fn ($b) => $b['id'] === $entry['id']); // don't reuse
                $matched++;
            }
        }

        return $matched;
    }

    public function match(FinBankStatementLine $line, int $journalLineId): FinBankMatch
    {
        if ($line->status === 'matched') {
            throw new PostingException('This statement line is already matched.');
        }
        if (FinBankMatch::where('journal_line_id', $journalLineId)->exists()) {
            throw new PostingException('That book entry is already reconciled.');
        }

        return $this->createMatch($line, $journalLineId, (float) $line->amount);
    }

    public function unmatch(FinBankMatch $match): void
    {
        DB::transaction(function () use ($match) {
            FinBankStatementLine::where('id', $match->statement_line_id)->update(['status' => 'unmatched']);
            $match->delete();
        });
    }

    /**
     * Post a book entry for a statement line against a contra account, then
     * reconcile it. Thin wrapper over allocate() for the plain account case.
     */
    public function createEntry(FinBankStatementLine $line, FinBankAccount $bank, string $contra, string $description): FinBankMatch
    {
        return $this->allocate($line, $bank, ['type' => 'account', 'contra' => $contra, 'description' => $description]);
    }

    /**
     * Allocate a statement line the "Sage way": to a GL account, a customer, or a
     * supplier. Customer/supplier post against the AR/AP control account (a receipt
     * / payment) and tag the party. Posts a balanced journal then reconciles.
     *
     * @param  array{type:string, contra?:string, party_id?:int, description?:string}  $opts
     */
    public function allocate(FinBankStatementLine $line, FinBankAccount $bank, array $opts): FinBankMatch
    {
        if ($line->status === 'matched') {
            throw new PostingException('This statement line is already matched.');
        }

        $type = $opts['type'] ?? 'account';
        $description = $opts['description'] ?? $line->description ?: 'Bank entry';
        $party = null;

        if ($type === 'customer' || $type === 'supplier') {
            $party = FinParty::findOrFail($opts['party_id'] ?? 0);
            $contra = config($type === 'customer' ? 'finance.ar_control' : 'finance.ap_control');
            $description = trim(($party->name) . ($opts['description'] ?? '' ? ' — ' . $opts['description'] : ''));
        } else {
            $contra = $opts['contra'] ?? null;
            if (! $contra) {
                throw new PostingException('Choose an account to allocate to.');
            }
        }

        $amount = round(abs((float) $line->amount), 2);
        $inflow = (float) $line->amount >= 0;

        return DB::transaction(function () use ($line, $bank, $contra, $description, $amount, $inflow, $party, $type) {
            $debits = $inflow
                ? [['account' => $bank->account_id, 'amount' => $amount, 'narration' => $description]]
                : [['account' => $contra, 'amount' => $amount, 'narration' => $description]];
            $credits = $inflow
                ? [['account' => $contra, 'amount' => $amount, 'narration' => $description]]
                : [['account' => $bank->account_id, 'amount' => $amount, 'narration' => $description]];

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'bank', eventType: 'bank.reconcile_entry', debits: $debits, credits: $credits,
                eventDate: $line->statement_date, companyId: $bank->company_id, currency: $bank->currency,
                sourceRef: $party ? "party:{$party->id}" : "statement_line:{$line->id}",
                description: $description ?: 'Bank reconciliation entry',
                metadata: array_filter(['party_id' => $party?->id, 'allocation_type' => $type]),
                idempotencyKey: 'bank_recon:' . $line->id,
            ));

            $bankLine = $journal->lines->firstWhere('account_id', $bank->account_id);

            return $this->createMatch($line, $bankLine->id, (float) $line->amount);
        });
    }

    /** Auto-allocate unmatched lines that match an active rule. Returns the count posted. */
    public function applyRules(FinBankAccount $bank): int
    {
        $rules = FinBankRule::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('bank_account_id')->orWhere('bank_account_id', $bank->id))
            ->orderBy('priority')->get();
        if ($rules->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($bank->statementLines()->where('status', 'unmatched')->get() as $line) {
            $rule = $rules->first(fn (FinBankRule $r) => $r->matches($line));
            if (! $rule) {
                continue;
            }
            try {
                $this->allocate($line, $bank, [
                    'type' => $rule->allocation_type,
                    'contra' => $rule->contraAccount?->code,
                    'party_id' => $rule->party_id,
                    'description' => $rule->name,
                ]);
                $count++;
            } catch (PostingException) {
                // skip a rule that can't post (e.g. deleted account) and carry on
            }
        }

        return $count;
    }

    /** Rule suggestions per unmatched line for the UI: [line_id => ['rule' => name, 'target' => label]]. */
    public function suggestions(FinBankAccount $bank): array
    {
        $rules = FinBankRule::with(['contraAccount', 'party'])->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('bank_account_id')->orWhere('bank_account_id', $bank->id))
            ->orderBy('priority')->get();
        if ($rules->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($bank->statementLines()->where('status', 'unmatched')->get() as $line) {
            $rule = $rules->first(fn (FinBankRule $r) => $r->matches($line));
            if ($rule) {
                $target = $rule->allocation_type === 'account'
                    ? ($rule->contraAccount ? "{$rule->contraAccount->code} · {$rule->contraAccount->name}" : 'account')
                    : ($rule->party?->name ?? $rule->allocation_type);
                $out[$line->id] = ['rule' => $rule->name, 'target' => $target];
            }
        }

        return $out;
    }

    public function summary(FinBankAccount $bank): array
    {
        $unmatchedStmt = $bank->statementLines()->where('status', 'unmatched')->count();
        $totalStmt = $bank->statementLines()->count();
        $book = collect($this->bookEntries($bank));

        return [
            'unmatched_statement' => $unmatchedStmt,
            'statement_total'     => $totalStmt,
            'unreconciled_book'   => $book->count(),
            'matched_pct'         => $totalStmt > 0 ? round(($totalStmt - $unmatchedStmt) / $totalStmt * 100) : 0,
        ];
    }

    private function createMatch(FinBankStatementLine $line, int $journalLineId, float $amount): FinBankMatch
    {
        return DB::transaction(function () use ($line, $journalLineId, $amount) {
            $match = FinBankMatch::create([
                'statement_line_id' => $line->id, 'journal_line_id' => $journalLineId,
                'amount' => round($amount, 2), 'created_by' => Auth::id(),
            ]);
            $line->update(['status' => 'matched']);

            return $match;
        });
    }
}
