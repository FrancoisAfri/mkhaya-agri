<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Accounting\Scopes\EntityScope;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxReconciliationItem;
use App\Modules\Finance\Tax\Models\TaxRuleVersion;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation runs (§12). Phase 1 implements the first and most important: the VAT register
 * against the VAT control accounts in the general ledger, matched journal by journal.
 *
 * Each run is a new record (TAX-REC-005) with its cutoff (TAX-REC-001). A signed-off run is never
 * rewritten; running again creates the next version beside it. Differences are classified
 * (TAX-REC-004) and nothing is ever written off or journalled from here (TAX-REC-008).
 */
class TaxReconciliationService
{
    /** At or under this, a difference between two matched amounts is rounding, not a discrepancy. */
    private const ROUNDING = '0.05';

    public function __construct(private readonly TaxAudit $audit, private readonly TaxPeriodService $periods)
    {
    }

    public function runVatToLedger(TaxPeriod $period, ?string $tolerance = null): TaxReconciliation
    {
        if ($period->taxType?->code !== 'VAT') {
            throw new TaxControlException('This reconciliation is for VAT periods.');
        }
        $this->periods->assertProcessable($period);
        $tolerance = bcadd($tolerance ?? (string) app(\App\Modules\Finance\Tax\Support\TaxSettings::class)->get('reconciliation_tolerance', $period->company_id), '0', 2);
        $cutoff = now();

        try {
            return DB::transaction(function () use ($period, $tolerance, $cutoff) {
                $previous = TaxReconciliation::query()->withoutGlobalScope(EntityScope::class)
                    ->where('period_id', $period->id)->where('type', 'vat_register_to_gl')->orderByDesc('version')->lockForUpdate()->first();

                $register = $this->registerByJournal($period);
                $ledger = $this->ledgerByJournal($period);
                $items = $this->match($register, $ledger, $period);

                $sourceTotal = '0';
                $targetTotal = '0';
                $counts = ['matched' => 0, 'unmatched' => 0, 'exception' => 0];
                foreach ($items as $i) {
                    $sourceTotal = bcadd($sourceTotal, $i['signed_source'], 2);
                    $targetTotal = bcadd($targetTotal, $i['signed_target'], 2);
                    $counts[$i['match_state'] === 'matched' ? 'matched' : ($i['match_state'] === 'exception' ? 'exception' : 'unmatched')]++;
                }
                $difference = bcsub($sourceTotal, $targetTotal, 2);

                $recon = TaxReconciliation::create([
                    'entity_id' => $period->entity_id, 'company_id' => $period->company_id,
                    'type' => 'vat_register_to_gl', 'period_id' => $period->id,
                    'version' => ($previous?->version ?? 0) + 1, 'supersedes_id' => $previous?->id,
                    'status' => 'completed', 'cutoff_at' => $cutoff, 'tolerance' => $tolerance,
                    'source_total' => $sourceTotal, 'target_total' => $targetTotal, 'difference' => $difference,
                    'matched_count' => $counts['matched'], 'unmatched_count' => $counts['unmatched'], 'exception_count' => $counts['exception'],
                    'summary' => $this->summarise($items, $tolerance),
                    'diagnostics' => ['control_accounts' => $this->controlCodes(), 'register_lines' => array_sum(array_column($register, 'lines'))],
                    'prepared_by' => Auth::id(),
                ]);

                foreach ($items as $i) {
                    unset($i['signed_source'], $i['signed_target']);
                    TaxReconciliationItem::create($i + ['company_id' => $period->company_id, 'reconciliation_id' => $recon->id]);
                }

                $this->audit->record('reconciliation.completed', $recon, ['period_id' => $period->id, 'tax_type' => 'VAT',
                    'after' => ['version' => $recon->version, 'difference' => $difference, 'exceptions' => $counts['exception']],
                    'context' => ['cutoff' => $cutoff->toIso8601String()]]);
                app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.reconciliation.completed', ['reconciliation_id' => $recon->id, 'company_id' => $recon->company_id]);
                if ($counts['exception'] > 0) {
                    app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.reconciliation.exception_detected', ['reconciliation_id' => $recon->id, 'company_id' => $recon->company_id]);
                }

                return $recon;
            });
        } catch (TaxControlException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // §24: a failed run is recorded with its diagnostics and is never marked complete.
            report($e);
            $this->audit->failed('reconciliation.run', $period, 'The reconciliation could not be completed.', ['period_id' => $period->id,
                'context' => ['error' => class_basename($e)]]);
            throw new TaxControlException('The reconciliation could not be completed. Nothing was saved; the failure has been logged.');
        }
    }

    /**
     * §7.4 / §12.1: payroll taxes in the register against the ledger accounts payroll posts to,
     * run by run. PAYE and employee UIF are posted by payroll (accounts 2300 and 2310 by default);
     * SDL and the employer's UIF are not posted by the company payroll engine today, so they appear
     * as missing on the ledger side — reported, not hidden, because an unaccrued liability is the
     * point of the reconciliation.
     */
    public function runPayrollToLedger(TaxPeriod $period, ?string $tolerance = null): TaxReconciliation
    {
        if ($period->taxType?->code !== 'PAYE') {
            throw new TaxControlException('This reconciliation is for PAYE (EMP201) periods.');
        }
        $this->periods->assertProcessable($period);
        $tolerance = bcadd($tolerance ?? (string) app(\App\Modules\Finance\Tax\Support\TaxSettings::class)->get('reconciliation_tolerance', $period->company_id), '0', 2);
        $map = app(\App\Modules\Finance\Accounting\Services\AccountMap::class);
        $accounts = [
            'PAYE' => $map->codeFor('payroll', 'paye', '2300'),
            'UIF_EMPLOYEE' => $map->codeFor('payroll', 'uif', '2310'),
            'SDL' => $map->codeFor('payroll', 'sdl', '2320'),
        ];

        return DB::transaction(function () use ($period, $tolerance, $accounts) {
            $previous = TaxReconciliation::query()->where('period_id', $period->id)->where('type', 'payroll_to_gl')
                ->orderByDesc('version')->lockForUpdate()->first();

            $rows = TaxTransaction::query()->current()->where('period_id', $period->id)->where('source_module', 'payroll')
                ->where('status', '!=', 'excluded')->with('taxType:id,code')->get();
            $items = [];
            $sourceTotal = '0';
            $targetTotal = '0';
            $counts = ['matched' => 0, 'unmatched' => 0, 'exception' => 0];

            foreach ($rows->groupBy(fn ($r) => $r->calculation['payroll_run_id'] ?? 0) as $runId => $runRows) {
                $journalId = $runRows->first()->journal_id;
                $sum = fn ($code, $part = null) => array_reduce($runRows->filter(fn ($r) => $r->taxType->code === $code)->all(),
                    fn ($c, $r) => bcadd($c, $part ? (string) ($r->calculation[$part] ?? '0') : (string) $r->tax_amount, 2), '0');
                $register = ['PAYE' => $sum('PAYE'), 'UIF_EMPLOYEE' => $sum('UIF', 'employee'), 'SDL' => $sum('SDL')];

                foreach ($register as $key => $amount) {
                    if ($key === 'SDL' && bccomp($amount, '0', 2) === 0) {
                        continue;
                    }
                    $ledger = $journalId ? $this->journalCredit((int) $journalId, (string) $accounts[$key]) : '0.00';
                    $diff = bcsub($amount, $ledger, 2);
                    $abs = ltrim($diff, '-');
                    [$state, $class] = match (true) {
                        bccomp($abs, '0', 2) === 0 => ['matched', null],
                        bccomp($abs, self::ROUNDING, 2) <= 0 => ['partial', 'rounding'],
                        ! $journalId || bccomp($ledger, '0', 2) === 0 => ['exception', 'missing'],
                        default => ['exception', 'genuine'],
                    };
                    $counts[$state === 'matched' ? 'matched' : ($state === 'exception' ? 'exception' : 'unmatched')]++;
                    $sourceTotal = bcadd($sourceTotal, $amount, 2);
                    $targetTotal = bcadd($targetTotal, $ledger, 2);
                    $label = ['PAYE' => 'PAYE', 'UIF_EMPLOYEE' => 'UIF (employee)', 'SDL' => 'SDL'][$key];
                    $items[] = [
                        'side' => $journalId ? 'both' : 'source', 'match_key' => "run:{$runId}:{$key}",
                        'reference' => $runRows->first()->document_number . ' · ' . $label,
                        'description' => $journalId ? "Payroll journal #{$journalId}, account {$accounts[$key]}"
                            : 'No payroll journal found for this run — it was not posted to the ledger',
                        'source_amount' => $amount, 'target_amount' => $ledger, 'difference' => $diff,
                        'match_state' => $state, 'difference_class' => $class,
                        'links' => ['payroll_run_id' => $runId, 'journal_id' => $journalId, 'account' => $accounts[$key]],
                    ];
                }
            }

            $recon = TaxReconciliation::create([
                'entity_id' => $period->entity_id, 'company_id' => $period->company_id, 'type' => 'payroll_to_gl', 'period_id' => $period->id,
                'version' => ($previous?->version ?? 0) + 1, 'supersedes_id' => $previous?->id, 'status' => 'completed', 'cutoff_at' => now(),
                'tolerance' => $tolerance, 'source_total' => $sourceTotal, 'target_total' => $targetTotal,
                'difference' => bcsub($sourceTotal, $targetTotal, 2), 'matched_count' => $counts['matched'],
                'unmatched_count' => $counts['unmatched'], 'exception_count' => $counts['exception'],
                'summary' => $this->summarise($items, $tolerance), 'diagnostics' => ['accounts' => $accounts, 'runs' => $rows->pluck('calculation.payroll_run_id')->unique()->count()],
                'prepared_by' => Auth::id(),
            ]);
            foreach ($items as $i) {
                TaxReconciliationItem::create($i + ['company_id' => $period->company_id, 'reconciliation_id' => $recon->id]);
            }
            $this->audit->record('reconciliation.completed', $recon, ['period_id' => $period->id, 'tax_type' => 'PAYE',
                'after' => ['type' => 'payroll_to_gl', 'version' => $recon->version, 'difference' => (string) $recon->difference]]);

            return $recon;
        });
    }

    private function journalCredit(int $journalId, string $accountCode): string
    {
        $row = DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_id', $journalId)->where('a.code', $accountCode)
            ->selectRaw('COALESCE(SUM(l.base_credit),0) - COALESCE(SUM(l.base_debit),0) as net')->first();

        return bcadd((string) ($row->net ?? '0'), '0', 2);
    }

    /**
     * §12.1 "input/output VAT against eligible purchase/sales transactions": every posted Finance
     * document carrying VAT in the period, against the register lines built from it. Catches a
     * document the register has not picked up (register not refreshed, a code or date problem) and a
     * register figure that no longer agrees with its document.
     */
    public function runRegisterCompleteness(TaxPeriod $period, ?string $tolerance = null): TaxReconciliation
    {
        if ($period->taxType?->code !== 'VAT') {
            throw new TaxControlException('This reconciliation is for VAT periods.');
        }
        $this->periods->assertProcessable($period);

        $docs = DB::table('fin_invoices')->where('company_id', $period->company_id)
            ->when($period->entity_id, fn ($q) => $q->where('entity_id', $period->entity_id))
            ->whereNotNull('journal_id')->where('status', '!=', 'void')->where('tax_total', '!=', 0)
            ->whereBetween('invoice_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->get(['id', 'number', 'direction', 'doc_type', 'tax_total', 'currency'])
            ->mapWithKeys(fn ($d) => ['fin_invoice_lines:' . $d->id => [
                'label' => ($d->direction === 'receivable' ? 'Sales ' : 'Purchase ') . ($d->doc_type === 'credit_note' ? 'credit note ' : 'invoice ') . $d->number,
                'amount' => bcmul((string) $d->tax_total, $d->doc_type === 'credit_note' ? '-1' : '1', 2), 'foreign' => $d->currency !== config('finance.base_currency', 'ZAR'),
            ]]);
        $register = TaxTransaction::query()->current()->where('period_id', $period->id)->where('status', '!=', 'excluded')
            ->where('source_table', 'fin_invoice_lines')->where('document_type', '!=', 'adjustment')
            ->get(['source_id', 'tax_amount', 'fx_rate'])->groupBy(fn ($t) => 'fin_invoice_lines:' . $t->source_id)
            ->map(fn ($g) => ['amount' => array_reduce($g->all(), fn ($c, $t) => bcadd($c, bcdiv((string) $t->tax_amount, (string) ($t->fx_rate ?: '1'), 2), 2), '0'),
                'lines' => $g->count()]);

        $items = [];
        foreach ($docs as $key => $d) {
            $r = $register[$key] ?? null;
            $diff = bcsub((string) ($r['amount'] ?? '0'), $d['amount'], 2);
            $state = ! $r ? 'exception' : (bccomp(ltrim($diff, '-'), '0', 2) === 0 ? 'matched' : (bccomp(ltrim($diff, '-'), self::ROUNDING, 2) <= 0 ? 'partial' : 'exception'));
            $items[] = ['side' => $r ? 'both' : 'target', 'match_key' => $key, 'reference' => $d['label'],
                'description' => $r ? "{$r['lines']} register line(s)" . ($d['foreign'] ? ' (document currency)' : '') : 'Posted in Finance with VAT, not in the register — refresh the register',
                'source_amount' => (string) ($r['amount'] ?? '0.00'), 'target_amount' => $d['amount'], 'difference' => $diff, 'match_state' => $state,
                'difference_class' => $state === 'matched' ? null : ($r ? ($state === 'partial' ? 'rounding' : 'genuine') : 'missing'),
                'links' => ['document' => $key]];
        }
        foreach ($register as $key => $r) {
            if (! isset($docs[$key])) {
                $items[] = ['side' => 'source', 'match_key' => $key, 'reference' => 'Register line(s) for ' . $key,
                    'description' => 'In the register but not a posted VAT document of this period now (voided, re-dated or VAT removed)',
                    'source_amount' => (string) $r['amount'], 'target_amount' => '0.00', 'difference' => (string) $r['amount'],
                    'match_state' => 'exception', 'difference_class' => 'timing', 'links' => ['document' => $key]];
            }
        }

        return $this->saveRun($period, 'register_completeness', $items, $tolerance, ['documents' => $docs->count()]);
    }

    /**
     * §12.1 "tax liabilities against recorded payments": each liability of the period against the
     * Finance payments allocated to it, and payments to SARS recorded in Finance around the due
     * date that are not allocated to any liability.
     */
    public function runLiabilitiesToPayments(TaxPeriod $period, ?string $tolerance = null): TaxReconciliation
    {
        $liabilities = \App\Modules\Finance\Tax\Models\TaxLiability::query()->where('period_id', $period->id)->where('status', '!=', 'cancelled')
            ->with(['allocations' => fn ($q) => $q->whereNull('reversed_at')])->get();
        $items = [];

        foreach ($liabilities as $l) {
            $owed = bcadd((string) $l->original_amount, (string) $l->adjustments, 2);
            $paidFromFinance = array_reduce($l->allocations->all(), fn ($c, $a) => bcadd($c, (string) $a->amount, 2), '0.00');
            $preMigration = $l->import_batch_id ? bcsub((string) $l->paid_amount, $paidFromFinance, 2) : '0.00';
            $paid = bcadd($paidFromFinance, $preMigration, 2);
            $diff = bcsub($owed, $paid, 2);
            $overdue = $l->due_date && $l->due_date->lt(today());
            $state = bccomp($diff, '0', 2) === 0 ? 'matched' : (bccomp($diff, '0', 2) < 0 ? 'exception' : ($overdue ? 'exception' : 'unmatched'));
            $items[] = ['side' => 'both', 'match_key' => 'liability:' . $l->id, 'reference' => $l->description,
                'description' => match (true) {
                    $state === 'matched' => 'Paid in full',
                    bccomp($diff, '0', 2) < 0 => 'Overpaid — confirm with SARS whether a refund or credit is due',
                    $overdue => 'Outstanding after the due date ' . $l->due_date->toDateString(),
                    default => 'Not yet paid; due ' . ($l->due_date?->toDateString() ?? '—'),
                } . (bccomp($preMigration, '0', 2) !== 0 ? " (includes {$preMigration} paid before migration)" : ''),
                'source_amount' => $owed, 'target_amount' => $paid, 'difference' => $diff, 'match_state' => $state,
                'difference_class' => $state === 'matched' ? null : ($state === 'unmatched' ? 'timing' : 'genuine'),
                'links' => ['liability' => $l->uuid]];
        }

        $allocated = \App\Modules\Finance\Tax\Models\TaxPaymentAllocation::query()->whereNull('reversed_at')->pluck('fin_payment_id')->all();
        // From the period's start (provisional and some payments are made before it ends) to a month
        // after the payment is due.
        $window = [$period->start_date->toDateString(), ($period->payment_due_date ?? $period->end_date)->copy()->addDays(30)->toDateString()];
        $unallocated = DB::table('fin_payments as p')->join('fin_parties as party', 'party.id', '=', 'p.party_id')
            ->where('p.company_id', $period->company_id)->where('p.direction', 'payment')
            ->when($period->entity_id, fn ($q) => $q->where('p.entity_id', $period->entity_id))
            ->where(fn ($q) => $q->where('party.name', 'ilike', '%SARS%')->orWhere('party.name', 'ilike', '%revenue service%'))
            ->whereBetween('p.payment_date', $window)->whereNotIn('p.id', $allocated ?: [0])
            ->get(['p.id', 'p.number', 'p.payment_date', 'p.amount', 'p.reference']);
        foreach ($unallocated as $p) {
            $items[] = ['side' => 'target', 'match_key' => 'payment:' . $p->id, 'reference' => "Payment {$p->number} ({$p->payment_date})",
                'description' => 'Paid to SARS in Finance but not allocated to any tax liability' . ($p->reference ? " — ref {$p->reference}" : ''),
                'source_amount' => '0.00', 'target_amount' => (string) $p->amount, 'difference' => bcmul((string) $p->amount, '-1', 2),
                'match_state' => 'exception', 'difference_class' => 'missing', 'links' => ['fin_payment_id' => $p->id]];
        }

        return $this->saveRun($period, 'liabilities_to_payments', $items, $tolerance, ['liabilities' => $liabilities->count(), 'unallocated_payments' => $unallocated->count()]);
    }

    /**
     * §12.2 step 6: pair an item found only on one side with one found only on the other — e.g. a
     * VAT journal posted under a different reference — with the reason. Both stay on the record;
     * the second is marked merged into the first.
     */
    public function matchManually(TaxReconciliationItem $a, TaxReconciliationItem $b, string $rationale): TaxReconciliationItem
    {
        if ($a->reconciliation_id !== $b->reconciliation_id || $a->id === $b->id) {
            throw new TaxControlException('Match two different items of the same reconciliation.');
        }
        $recon = TaxReconciliation::query()->findOrFail($a->reconciliation_id);
        if ($recon->status !== 'completed') {
            throw new TaxControlException('Only a completed, unsigned reconciliation can be changed.');
        }
        if ($a->match_state === 'matched' || $b->match_state === 'matched' || $a->match_state === 'merged' || $b->match_state === 'merged') {
            throw new TaxControlException('Only unmatched items can be matched.');
        }
        if (mb_strlen(trim($rationale)) < 10) {
            throw new TaxControlException('Say why these belong together (at least 10 characters).');
        }
        [$source, $target] = $a->side === 'target' && $b->side !== 'target' ? [$b, $a] : [$a, $b];

        return DB::transaction(function () use ($recon, $source, $target, $rationale) {
            $sourceAmount = (string) $source->source_amount;
            $targetAmount = bcadd((string) $source->target_amount, (string) $target->target_amount, 2);
            $diff = bcsub($sourceAmount, $targetAmount, 2);
            $state = bccomp($diff, '0', 2) === 0 ? 'matched' : (bccomp(ltrim($diff, '-'), self::ROUNDING, 2) <= 0 ? 'partial' : 'exception');
            $source->forceFill(['side' => 'both', 'target_amount' => $targetAmount, 'difference' => $diff, 'match_state' => $state,
                'difference_class' => $state === 'matched' ? null : ($state === 'partial' ? 'rounding' : 'genuine'),
                'explanation' => 'Matched manually with ' . $target->reference . ': ' . $rationale, 'explained_by' => Auth::id(), 'explained_at' => now(),
                'links' => array_merge($source->links ?? [], ['matched_with' => $target->id])])->save();
            $target->forceFill(['match_state' => 'merged', 'difference' => '0.00', 'explanation' => 'Matched manually into ' . $source->reference . ': ' . $rationale,
                'explained_by' => Auth::id(), 'explained_at' => now(), 'links' => array_merge($target->links ?? [], ['merged_into' => $source->id])])->save();
            $this->audit->record('reconciliation.items_matched', $recon, ['period_id' => $recon->period_id, 'reason' => $rationale,
                'context' => ['source_item' => $source->id, 'target_item' => $target->id, 'difference' => $diff]]);

            return $source;
        });
    }

    /** §12.2 step 8: give an unresolved difference to someone to investigate. */
    public function assign(TaxReconciliationItem $item, ?int $userId): TaxReconciliationItem
    {
        $recon = TaxReconciliation::query()->findOrFail($item->reconciliation_id);
        $before = $item->assigned_to;
        $item->forceFill(['assigned_to' => $userId])->save();
        $this->audit->record('reconciliation.item_assigned', $recon, ['period_id' => $recon->period_id,
            'before' => ['assigned_to' => $before], 'after' => ['assigned_to' => $userId], 'context' => ['item_id' => $item->id]]);

        return $item;
    }

    /** Persist a run built by one of the reconciliation types: versioned, cut off, summarised. */
    private function saveRun(TaxPeriod $period, string $type, array $items, ?string $tolerance, array $diagnostics): TaxReconciliation
    {
        $tolerance = bcadd($tolerance ?? (string) app(\App\Modules\Finance\Tax\Support\TaxSettings::class)->get('reconciliation_tolerance', $period->company_id), '0', 2);

        return DB::transaction(function () use ($period, $type, $items, $tolerance, $diagnostics) {
            $previous = TaxReconciliation::query()->where('period_id', $period->id)->where('type', $type)->orderByDesc('version')->lockForUpdate()->first();
            $sum = fn ($k) => array_reduce($items, fn ($c, $i) => bcadd($c, (string) $i[$k], 2), '0.00');
            $count = fn ($s) => count(array_filter($items, fn ($i) => $i['match_state'] === $s));

            $recon = TaxReconciliation::create([
                'entity_id' => $period->entity_id, 'company_id' => $period->company_id, 'type' => $type, 'period_id' => $period->id,
                'version' => ($previous?->version ?? 0) + 1, 'supersedes_id' => $previous?->id, 'status' => 'completed', 'cutoff_at' => now(),
                'tolerance' => $tolerance, 'source_total' => $sum('source_amount'), 'target_total' => $sum('target_amount'),
                'difference' => bcsub($sum('source_amount'), $sum('target_amount'), 2), 'matched_count' => $count('matched'),
                'unmatched_count' => $count('unmatched') + $count('partial'), 'exception_count' => $count('exception'),
                'summary' => $this->summarise($items, $tolerance), 'diagnostics' => $diagnostics, 'prepared_by' => Auth::id(),
            ]);
            foreach ($items as $i) {
                TaxReconciliationItem::create($i + ['company_id' => $period->company_id, 'reconciliation_id' => $recon->id]);
            }
            $this->audit->record('reconciliation.completed', $recon, ['period_id' => $period->id,
                'after' => ['type' => $type, 'version' => $recon->version, 'difference' => (string) $recon->difference, 'exceptions' => $recon->exception_count]]);
            if ($recon->exception_count > 0) {
                app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.reconciliation.exception_detected', ['reconciliation_id' => $recon->id, 'company_id' => $recon->company_id]);
            }

            return $recon;
        });
    }

    /** TAX-REC-003: explain an item, optionally reclassifying its difference. */
    public function explain(TaxReconciliationItem $item, string $explanation, ?string $class = null): TaxReconciliationItem
    {
        $recon = TaxReconciliation::query()->withoutGlobalScope(EntityScope::class)->findOrFail($item->reconciliation_id);
        if ($recon->status === 'signed_off') {
            throw new TaxControlException('This reconciliation is signed off. Reopen it, or run a new version.');
        }
        if ($class !== null && ! in_array($class, ['timing', 'rounding', 'missing', 'genuine'], true)) {
            throw new TaxControlException('Unknown difference class.');
        }

        return DB::transaction(function () use ($item, $recon, $explanation, $class) {
            $before = $item->only(['explanation', 'difference_class']);
            $item->forceFill(['explanation' => $explanation, 'difference_class' => $class ?? $item->difference_class,
                'explained_by' => Auth::id(), 'explained_at' => now()])->save();
            $this->audit->record('reconciliation.item_explained', $recon, ['period_id' => $recon->period_id, 'reason' => $explanation,
                'before' => $before, 'after' => $item->only(['explanation', 'difference_class']), 'context' => ['item_id' => $item->id]]);

            return $item;
        });
    }

    /** TAX-REC-006/007: sign-off by someone other than the preparer, once every difference over tolerance is explained. */
    public function signOff(TaxReconciliation $recon, ?string $comment = null): TaxReconciliation
    {
        if ($recon->status !== 'completed') {
            throw new TaxControlException('Only a completed reconciliation can be signed off.');
        }
        $latest = TaxReconciliation::query()->where('period_id', $recon->period_id)
            ->where('type', $recon->type)->max('version');
        if ($latest !== $recon->version) {
            throw new TaxControlException('A newer run exists for this period. Sign off the latest one.');
        }
        if ($recon->prepared_by && $recon->prepared_by === Auth::id()) {
            $this->audit->denied('reconciliation.sign_off', $recon, 'Self sign-off refused (segregation of duties).', ['period_id' => $recon->period_id]);
            throw new TaxControlException('You ran this reconciliation, so someone else has to sign it off.');
        }

        $unexplained = $recon->items()->whereNotIn('match_state', ['matched', 'merged'])
            ->whereRaw('ABS(difference) > ?', [$recon->tolerance])->whereNull('explanation')->count();
        if ($unexplained > 0) {
            throw new TaxControlException("{$unexplained} difference(s) above the R{$recon->tolerance} tolerance still need an explanation.");
        }

        return DB::transaction(function () use ($recon, $comment) {
            $recon->forceFill(['status' => 'signed_off', 'signed_off_by' => Auth::id(), 'signed_off_at' => now(), 'sign_off_comment' => $comment])->save();
            $this->audit->record('reconciliation.signed_off', $recon, ['period_id' => $recon->period_id, 'reason' => $comment,
                'after' => ['version' => $recon->version, 'difference' => (string) $recon->difference]]);

            return $recon;
        });
    }

    /** Controlled reopening: the record stays, its status says it is no longer relied on. */
    public function reopen(TaxReconciliation $recon, string $reason): TaxReconciliation
    {
        if ($recon->status !== 'signed_off') {
            throw new TaxControlException('Only a signed-off reconciliation is reopened.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new TaxControlException('Give the reason for reopening (at least 10 characters).');
        }

        return DB::transaction(function () use ($recon, $reason) {
            $recon->forceFill(['status' => 'reopened'])->save();
            $this->audit->record('reconciliation.reopened', $recon, ['period_id' => $recon->period_id, 'reason' => $reason,
                'before' => ['status' => 'signed_off'], 'after' => ['status' => 'reopened']]);

            return $recon;
        });
    }

    // ── Matching ─────────────────────────────────────────────────────────────

    /** @return array<string, array{direction:string, journal_id:?int, amount:string, lines:int, documents:list<string>}> */
    private function registerByJournal(TaxPeriod $period): array
    {
        $out = [];
        $rows = TaxTransaction::query()->withoutGlobalScope(EntityScope::class)->current()
            ->where('period_id', $period->id)->where('status', '!=', 'excluded')->get();

        foreach ($rows as $r) {
            $key = $r->direction . ':' . ($r->journal_id ?? 'none:' . $r->id);
            $out[$key] ??= ['direction' => $r->direction, 'journal_id' => $r->journal_id, 'amount' => '0', 'lines' => 0, 'documents' => []];
            $out[$key]['amount'] = bcadd($out[$key]['amount'], (string) $r->tax_amount, 2);
            $out[$key]['lines']++;
            $out[$key]['documents'][] = $r->document_number;
        }

        return $out;
    }

    /** @return array<string, array{direction:string, journal_id:int, amount:string, date:string, description:?string}> */
    private function ledgerByJournal(TaxPeriod $period): array
    {
        $codes = $this->controlCodes();
        $out = [];

        foreach (['output' => $codes['output'], 'input' => $codes['input']] as $direction => $accountCodes) {
            $rows = DB::table('fin_journal_lines as l')
                ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
                ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
                ->whereIn('a.code', $accountCodes)
                ->where('j.status', '!=', 'draft')
                ->when($period->entity_id, fn ($q) => $q->where('l.entity_id', $period->entity_id))
                ->where('l.company_id', $period->company_id)
                ->whereBetween('j.journal_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
                ->groupBy('j.id', 'j.journal_date', 'j.description', 'j.journal_no')
                ->selectRaw('j.id, j.journal_date, j.description, j.journal_no, COALESCE(SUM(l.base_debit),0) d, COALESCE(SUM(l.base_credit),0) c')
                ->get();

            foreach ($rows as $r) {
                // Output VAT is a liability (credit-normal); input VAT an asset (debit-normal).
                $amount = $direction === 'output' ? bcsub((string) $r->c, (string) $r->d, 2) : bcsub((string) $r->d, (string) $r->c, 2);
                $out["{$direction}:{$r->id}"] = ['direction' => $direction, 'journal_id' => (int) $r->id, 'amount' => $amount,
                    'date' => (string) $r->journal_date, 'description' => trim(($r->journal_no ? $r->journal_no . ' ' : '') . ($r->description ?? ''))];
            }
        }

        return $out;
    }

    private function match(array $register, array $ledger, TaxPeriod $period): array
    {
        $items = [];
        $sign = fn (string $direction, string $amount) => $direction === 'output' ? $amount : bcmul($amount, '-1', 2);

        foreach ($register as $key => $r) {
            $g = $ledger[$key] ?? null;
            $target = $g['amount'] ?? '0.00';
            $diff = bcsub($r['amount'], $target, 2);
            $abs = ltrim($diff, '-');

            if ($g) {
                [$state, $class] = match (true) {
                    bccomp($abs, '0', 2) === 0 => ['matched', null],
                    bccomp($abs, self::ROUNDING, 2) <= 0 => ['partial', 'rounding'],
                    default => ['exception', 'genuine'],
                };
            } else {
                // Posted outside the period (timing), or the VAT never reached a control account.
                $elsewhere = $r['journal_id'] ? DB::table('fin_journals')->where('id', $r['journal_id'])->value('journal_date') : null;
                [$state, $class] = $elsewhere && ($elsewhere < $period->start_date->toDateString() || $elsewhere > $period->end_date->toDateString())
                    ? ['unmatched', 'timing'] : ['exception', 'missing'];
            }

            $items[] = [
                'side' => $g ? 'both' : 'source', 'match_key' => $key,
                'reference' => $r['journal_id'] ? "Journal #{$r['journal_id']}" : 'No journal',
                'description' => ucfirst($r['direction']) . ' VAT · ' . implode(', ', array_slice(array_unique($r['documents']), 0, 5)),
                'source_amount' => $r['amount'], 'target_amount' => $target, 'difference' => $diff,
                'match_state' => $state, 'difference_class' => $class,
                'links' => ['journal_id' => $r['journal_id'], 'documents' => array_values(array_unique($r['documents']))],
                'signed_source' => $sign($r['direction'], $r['amount']), 'signed_target' => $sign($r['direction'], $target),
            ];
        }

        foreach ($ledger as $key => $g) {
            if (isset($register[$key])) {
                continue;
            }
            $items[] = [
                'side' => 'target', 'match_key' => $key, 'reference' => "Journal #{$g['journal_id']}",
                'description' => ucfirst($g['direction']) . ' VAT posted with no register line · ' . ($g['description'] ?: 'manual journal'),
                'source_amount' => '0.00', 'target_amount' => $g['amount'], 'difference' => bcmul($g['amount'], '-1', 2),
                'match_state' => bccomp(ltrim($g['amount'], '-'), '0', 2) === 0 ? 'matched' : 'exception',
                'difference_class' => 'missing', 'links' => ['journal_id' => $g['journal_id'], 'date' => $g['date']],
                'signed_source' => '0.00', 'signed_target' => $sign($g['direction'], $g['amount']),
            ];
        }

        return $items;
    }

    private function summarise(array $items, string $tolerance): array
    {
        $by = [];
        foreach ($items as $i) {
            $c = $i['difference_class'] ?? 'none';
            $by[$c] ??= ['count' => 0, 'amount' => '0'];
            $by[$c]['count']++;
            $by[$c]['amount'] = bcadd($by[$c]['amount'], $i['difference'], 2);
        }
        $over = count(array_filter($items, fn ($i) => $i['match_state'] !== 'matched' && bccomp(ltrim($i['difference'], '-'), $tolerance, 2) > 0));

        return ['by_class' => $by, 'over_tolerance' => $over];
    }

    /** Control account codes: Finance's configured VAT accounts plus any a published rule maps to. */
    private function controlCodes(): array
    {
        $mapped = TaxRuleVersion::query()->where('status', 'published')->whereNotNull('control_account_id')
            ->get(['direction', 'control_account_id']);
        $codes = ['output' => [(string) config('finance.vat_output')], 'input' => [(string) config('finance.vat_input')]];

        foreach ($mapped as $m) {
            $code = DB::table('fin_accounts')->where('id', $m->control_account_id)->value('code');
            if ($code && in_array($m->direction, ['output', 'input'], true)) {
                $codes[$m->direction][] = (string) $code;
            }
        }

        return ['output' => array_values(array_unique($codes['output'])), 'input' => array_values(array_unique($codes['input']))];
    }
}
