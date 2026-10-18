<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxAccountClassification;
use App\Modules\Finance\Tax\Models\TaxCarryForward;
use App\Modules\Finance\Tax\Models\TaxComputation;
use App\Modules\Finance\Tax\Models\TaxComputationLine;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPaymentAllocation;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Corporate income tax and provisional tax (§8).
 *
 * The computation runs from accounting profit — the business's own income and expense movement for
 * its year, read from the posted ledger (year-end closing entries excluded) — to taxable income:
 *
 *   accounting profit
 *   + add-backs        expenses classified not deductible or capital, and manual add-backs
 *   − deductions       allowable amounts not in profit (manual, each with its section reference)
 *   − exempt income    income classified exempt
 *   − allowances       capital allowances (manual, from the asset schedule)
 *   − loss set-off     assessed loss brought forward, limited as config/tax.php says
 *   = taxable income   × the published CIT rule in force at year end = tax
 *   − credits          provisional tax actually paid for the year (allocated Finance payments)
 *   = payable / (refundable)
 *
 * Nothing is a calculation the user cannot see: every number is a line with its source and, for
 * anything typed in, a reason. Preparing again keeps the manual lines and re-reads the ledger.
 * Review and approval follow the same three-person rule as returns. NSEZ's tax professionals
 * confirm the treatments before reliance (SRS §28).
 */
class IncomeTaxService
{
    public function __construct(
        private readonly TaxAudit $audit,
        private readonly TaxPeriodService $periods,
        private readonly TaxRuleService $rules,
        private readonly TaxCalculator $calculator,
    ) {
    }

    // ── Ledger and classification ────────────────────────────────────────────

    /**
     * Income and expense movement per account for the year, signed so that profit = the sum
     * (income positive, expenses negative).
     *
     * @return Collection<int, object{account_id:int, code:string, name:string, type:string, amount:string}>
     */
    public function profitAndLoss(int $companyId, ?int $entityId, Carbon $from, Carbon $to): Collection
    {
        return DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)
            ->when($entityId, fn ($q) => $q->where('l.entity_id', $entityId))
            ->whereIn('a.type', ['income', 'expense'])
            ->where('j.status', '!=', 'draft')
            ->where('j.source_module', '!=', 'year_end')
            ->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.id as account_id, a.code, a.name, a.type, COALESCE(SUM(l.base_credit),0) - COALESCE(SUM(l.base_debit),0) as amount')
            ->get()
            ->map(function ($r) {
                $r->amount = bcadd((string) $r->amount, '0', 2);

                return $r;
            })
            ->filter(fn ($r) => bccomp($r->amount, '0', 2) !== 0)
            ->values();
    }

    public function classify(?int $entityId, int $accountId, string $treatment, ?string $note = null): TaxAccountClassification
    {
        if (! array_key_exists($treatment, TaxAccountClassification::TREATMENTS)) {
            throw new TaxControlException('Unknown treatment.');
        }

        return DB::transaction(function () use ($entityId, $accountId, $treatment, $note) {
            $row = TaxAccountClassification::query()->where('entity_id', $entityId)->where('account_id', $accountId)->first();
            $before = $row?->only(['treatment', 'note']);
            $row ??= new TaxAccountClassification(['entity_id' => $entityId, 'account_id' => $accountId]);
            $row->fill(['treatment' => $treatment, 'note' => $note, 'updated_by' => Auth::id()])->save();
            $this->audit->record('income_tax.classified', $row, ['entity_id' => $entityId, 'tax_type' => 'CIT', 'reason' => $note,
                'before' => $before, 'after' => ['account_id' => $accountId, 'treatment' => $treatment]]);

            return $row;
        });
    }

    // ── Corporate income tax computation ─────────────────────────────────────

    public function prepareCit(TaxPeriod $period): TaxComputation
    {
        if ($period->taxType?->code !== 'CIT') {
            throw new TaxControlException('An income tax computation is prepared for a corporate income tax (CIT) period.');
        }
        $this->periods->assertProcessable($period);
        [$start, $end] = [$period->start_date->copy(), $period->end_date->copy()];

        return DB::transaction(function () use ($period, $start, $end) {
            $previous = $this->previous($period, 'cit', null);
            $ledger = $this->profitAndLoss((int) $period->company_id, $period->entity_id, $start, $end);
            $classes = TaxAccountClassification::query()->where('entity_id', $period->entity_id)->pluck('treatment', 'account_id');

            $comp = $this->newVersion($period, 'cit', null, $previous, $start, $end, 'trial_balance');
            $sort = 0;
            $unclassified = [];

            foreach ($ledger as $r) {
                $treatment = $classes[$r->account_id] ?? null;
                if ($treatment === 'ignore') {
                    continue;
                }
                $this->line($comp, 'profit', $r->amount, "{$r->code} {$r->name}", 'ledger', $sort++, $r->account_id, $r->code);

                $abs = ltrim($r->amount, '-');
                if ($treatment === null) {
                    $unclassified[] = "{$r->code} {$r->name}";
                } elseif (in_array($treatment, ['non_deductible', 'capital'], true) && $r->type === 'expense') {
                    $this->line($comp, 'add_back', $abs, "{$r->code} {$r->name}", 'classification', $sort++, $r->account_id, $r->code,
                        TaxAccountClassification::TREATMENTS[$treatment]);
                } elseif ($treatment === 'exempt' && $r->type === 'income') {
                    $this->line($comp, 'exempt', $abs, "{$r->code} {$r->name}", 'classification', $sort++, $r->account_id, $r->code,
                        TaxAccountClassification::TREATMENTS[$treatment]);
                }
            }

            $this->carryManualLines($previous, $comp, $sort);
            $this->recompute($comp);

            $comp->forceFill(['snapshot' => ['accounts' => $ledger->count(), 'unclassified' => $unclassified,
                'hash' => hash('sha256', $ledger->map(fn ($r) => $r->account_id . ':' . $r->amount)->implode('|')), 'taken_at' => now()->toIso8601String()]])->save();
            $this->validate($comp);
            $this->audit->record('income_tax.prepared', $comp, ['period_id' => $period->id, 'tax_type' => 'CIT',
                'after' => ['version' => $comp->version, 'taxable_income' => (string) $comp->taxable_income, 'tax' => (string) $comp->tax]]);

            return $comp->fresh('lines');
        });
    }

    // ── Provisional tax ──────────────────────────────────────────────────────

    /**
     * An IRP6 estimate for a provisional period. The estimate is the expected taxable income for
     * the whole year; tax on it at the CIT rule in force at year end; the first payment is the
     * configured share of that tax, the second (and a voluntary third) the balance after what was
     * already estimated and approved for earlier periods.
     */
    public function prepareProvisional(TaxPeriod $period, string $estimate, string $basis, ?string $basicAmount = null, ?int $number = null): TaxComputation
    {
        if ($period->taxType?->code !== 'PROV') {
            throw new TaxControlException('A provisional estimate is prepared for a provisional tax (PROV) period.');
        }
        if (! is_numeric($estimate)) {
            throw new TaxControlException('Enter the estimated taxable income for the year.');
        }
        $this->periods->assertProcessable($period);
        [$yearStart, $yearEnd] = $this->yearFor($period);
        $number ??= $period->end_date->isSameMonth($yearEnd) ? 2 : 1;

        return DB::transaction(function () use ($period, $estimate, $basis, $basicAmount, $number, $yearStart, $yearEnd) {
            $previous = $this->previous($period, 'provisional', $number);
            $comp = $this->newVersion($period, 'provisional', $number, $previous, $yearStart, $yearEnd, $basis);
            $comp->forceFill(['basic_amount' => $basicAmount === null || $basicAmount === '' ? null : bcadd($basicAmount, '0', 2)])->save();

            $this->line($comp, 'profit', bcadd($estimate, '0', 2), 'Estimated taxable income for the year (' . str_replace('_', ' ', $basis) . ')', 'manual', 0, null, null,
                'Estimate for provisional tax');
            $sort = 1;
            $this->carryManualLines($previous, $comp, $sort, except: ['profit']);
            $this->recompute($comp);
            $this->validate($comp);
            $this->audit->record('provisional_tax.prepared', $comp, ['period_id' => $period->id, 'tax_type' => 'PROV',
                'after' => ['version' => $comp->version, 'number' => $number, 'estimate' => $estimate, 'payable' => (string) $comp->amount_payable]]);

            return $comp->fresh('lines');
        });
    }

    // ── Adjustments ──────────────────────────────────────────────────────────

    public function addLine(TaxComputation $comp, string $section, string $amount, string $description, string $reason, ?string $reference = null): TaxComputationLine
    {
        $this->expect($comp, ['prepared'], 'Only a prepared computation can be changed. Send it back first.');
        if (! in_array($section, ['add_back', 'deduction', 'exempt', 'allowance'], true)) {
            throw new TaxControlException('Adjustments are add-backs, deductions, exempt income or allowances.');
        }
        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw new TaxControlException('Enter a positive amount; the section decides whether it adds or deducts.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new TaxControlException('Explain the adjustment (at least 10 characters). It is kept with the computation.');
        }

        return DB::transaction(function () use ($comp, $section, $amount, $description, $reason, $reference) {
            $line = $this->line($comp, $section, bcadd($amount, '0', 2), $description, 'manual', (int) $comp->lines()->max('sort') + 1, null, null, $reason, $reference);
            $this->recompute($comp);
            $this->validate($comp);
            $this->audit->record('income_tax.adjusted', $comp, ['period_id' => $comp->period_id, 'reason' => $reason,
                'after' => ['section' => $section, 'amount' => $line->amount, 'description' => $description, 'reference' => $reference]]);

            return $line;
        });
    }

    public function removeLine(TaxComputation $comp, TaxComputationLine $line, string $reason): void
    {
        $this->expect($comp, ['prepared'], 'Only a prepared computation can be changed.');
        if ($line->computation_id !== $comp->id || $line->source !== 'manual' || $line->section === 'profit') {
            throw new TaxControlException('Only a manual adjustment can be removed; ledger lines change in the ledger.');
        }
        DB::transaction(function () use ($comp, $line, $reason) {
            $before = $line->only(['section', 'amount', 'description', 'reason']);
            $line->delete();
            $this->recompute($comp);
            $this->validate($comp);
            $this->audit->record('income_tax.adjustment_removed', $comp, ['period_id' => $comp->period_id, 'reason' => $reason, 'before' => $before]);
        });
    }

    // ── Workflow ─────────────────────────────────────────────────────────────

    public function sendForReview(TaxComputation $comp): TaxComputation
    {
        $this->expect($comp, ['prepared'], 'Only a prepared computation is sent for review.');
        $v = $this->validate($comp);
        if ($v['errors']) {
            throw new TaxControlException('Fix these first: ' . implode(' ', $v['errors']));
        }
        $comp->forceFill(['status' => 'under_review'])->save();
        $this->audit->record('income_tax.sent_for_review', $comp, ['period_id' => $comp->period_id]);

        return $comp;
    }

    public function review(TaxComputation $comp, bool $pass, string $comment): TaxComputation
    {
        $this->expect($comp, ['under_review'], 'Only a computation under review is reviewed.');
        $this->segregate($comp, 'review', [$comp->prepared_by]);
        if (mb_strlen(trim($comment)) < 3) {
            throw new TaxControlException('Add a review comment.');
        }
        $comp->forceFill($pass
            ? ['reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'review_comment' => $comment]
            : ['status' => 'prepared', 'reviewed_by' => null, 'reviewed_at' => null, 'review_comment' => $comment])->save();
        $this->audit->record($pass ? 'income_tax.reviewed' : 'income_tax.returned', $comp, ['period_id' => $comp->period_id, 'reason' => $comment]);

        return $comp;
    }

    /**
     * Approval fixes the computation as the version relied on. A CIT approval records the assessed
     * loss carried to next year and the balance expected to be payable on assessment; a provisional
     * approval records the IRP6 payment due.
     */
    public function approve(TaxComputation $comp, ?string $comment = null): TaxComputation
    {
        $this->expect($comp, ['under_review'], 'Only a reviewed computation can be approved.');
        if (! $comp->reviewed_by) {
            throw new TaxControlException('The computation has not been reviewed yet.');
        }
        $this->segregate($comp, 'approve', [$comp->prepared_by, $comp->reviewed_by]);
        $v = $this->validate($comp);
        if ($v['errors']) {
            throw new TaxControlException('This computation cannot be approved: ' . implode(' ', $v['errors']));
        }

        return DB::transaction(function () use ($comp, $comment) {
            $comp->forceFill(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(), 'approval_comment' => $comment])->save();

            if ($comp->kind === 'cit') {
                $this->recordCarryForward($comp);
            }
            $this->recordLiability($comp);
            $this->audit->record($comp->kind === 'cit' ? 'income_tax.approved' : 'provisional_tax.approved', $comp, ['period_id' => $comp->period_id,
                'reason' => $comment, 'after' => ['taxable_income' => (string) $comp->taxable_income, 'tax' => (string) $comp->tax, 'payable' => (string) $comp->amount_payable]]);

            return $comp->fresh();
        });
    }

    /**
     * §8.2 / §12.1: estimates, payments, the computation and the assessment for one year, side by
     * side, with what is still outstanding. Read-only; nothing is written off from here.
     *
     * @return array<string, mixed>
     */
    public function yearPosition(?int $entityId, Carbon $yearEnd): array
    {
        $comps = TaxComputation::query()->where('entity_id', $entityId)->whereDate('year_end', $yearEnd->toDateString())
            ->where('status', 'approved')->orderBy('kind')->orderBy('provisional_number')->get();
        $liabilities = TaxLiability::query()->where('entity_id', $entityId)
            ->whereHas('taxType', fn ($q) => $q->whereIn('code', ['CIT', 'PROV']))
            ->whereHas('period', fn ($q) => $q->whereDate('end_date', '<=', $yearEnd)->whereDate('end_date', '>', $yearEnd->copy()->subYear()))
            ->with('taxType:id,code')->get();

        $estimates = $comps->where('kind', 'provisional')->map(fn ($c) => ['number' => $c->provisional_number, 'estimate' => (string) $c->taxable_income,
            'tax' => (string) $c->tax, 'payable' => (string) $c->amount_payable])->values();
        $cit = $comps->firstWhere('kind', 'cit');
        $assessed = $liabilities->where('source', 'assessment');
        $sum = fn ($items, $f) => array_reduce($items->all(), fn ($c, $i) => bcadd($c, (string) (is_array($i) ? $i[$f] : $i->{$f}), 2), '0.00');

        return [
            'estimates' => $estimates,
            'provisional_payable' => $sum($estimates, 'payable'),
            'provisional_paid' => $this->provisionalPaid($entityId, $yearEnd),
            'computed_tax' => $cit ? (string) $cit->tax : null,
            'assessed' => $assessed->isEmpty() ? null : $sum($assessed, 'original_amount'),
            'outstanding' => array_reduce($liabilities->all(), fn ($c, $l) => bcadd($c, $l->outstanding(), 2), '0.00'),
            'difference_to_assessment' => $cit && $assessed->isNotEmpty() ? bcsub($sum($assessed, 'original_amount'), bcsub((string) $cit->tax, (string) $cit->credits, 2), 2) : null,
        ];
    }

    /**
     * §23: a balance brought in from before the system — an assessed loss per the last SARS
     * assessment, for example. It is marked as an opening balance with its basis, so it is never
     * mistaken for a figure this module computed.
     */
    public function recordOpeningBalance(?int $entityId, string $kind, string $yearEnd, string $closing, string $basis): TaxCarryForward
    {
        if (! in_array($kind, ['assessed_loss', 'allowance_balance'], true) || ! is_numeric($closing) || bccomp($closing, '0', 2) < 0) {
            throw new TaxControlException('Record a positive balance of a known kind.');
        }
        if (mb_strlen(trim($basis)) < 10) {
            throw new TaxControlException('Say where the balance comes from (e.g. "ITA34 for 2025, assessed loss R…").');
        }
        if (TaxCarryForward::query()->where('entity_id', $entityId)->where('kind', $kind)->whereDate('year_end', $yearEnd)->where('source', 'computation')->exists()) {
            throw new TaxControlException('That year already has a balance from an approved computation.');
        }

        return DB::transaction(function () use ($entityId, $kind, $yearEnd, $closing, $basis) {
            $row = TaxCarryForward::query()->updateOrCreate(
                ['entity_id' => $entityId, 'kind' => $kind, 'year_end' => $yearEnd],
                ['opening' => '0.00', 'movement' => bcadd($closing, '0', 2), 'closing' => bcadd($closing, '0', 2), 'source' => 'opening_balance',
                    'note' => $basis, 'created_by' => Auth::id()]
            );
            $this->audit->record('income_tax.opening_balance', $row, ['entity_id' => $entityId, 'tax_type' => 'CIT', 'reason' => $basis,
                'after' => ['kind' => $kind, 'year_end' => $yearEnd, 'closing' => (string) $row->closing]]);

            return $row;
        });
    }

    /** §15: evidence (assessment, asset schedule…) through Document Management, linked to the computation. */
    public function attachEvidence(TaxComputation $comp, \App\Modules\Operations\DocumentManagement\Models\Document $document, string $label): void
    {
        $comp->attachDocument($document, 'tax:computation');
        $this->audit->record('income_tax.evidence_attached', $comp, ['period_id' => $comp->period_id, 'context' => ['document_id' => $document->id, 'label' => $label]]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @return array{errors: list<string>, warnings: list<string>} */
    public function validate(TaxComputation $comp): array
    {
        $errors = [];
        $warnings = [];

        if (! $comp->rule_version_id) {
            $errors[] = 'No published CIT rule is in force at ' . $comp->year_end->toDateString() . '. Publish one under Tax rules (code CIT).';
        }
        if ($comp->kind === 'cit') {
            foreach (array_slice($comp->snapshot['unclassified'] ?? [], 0, 10) as $account) {
                $warnings[] = "{$account} is not classified for tax, so it is treated as taxable income or a deductible expense. Classify it to confirm.";
            }
            if (count($comp->snapshot['unclassified'] ?? []) > 10) {
                $warnings[] = (count($comp->snapshot['unclassified']) - 10) . ' more account(s) are not classified.';
            }
            if (($comp->snapshot['accounts'] ?? 0) === 0) {
                $errors[] = 'No income or expense was posted to the ledger for this year.';
            }
        } else {
            if ($comp->basic_amount !== null && bccomp((string) $comp->taxable_income, (string) $comp->basic_amount, 2) < 0) {
                $warnings[] = "The estimate is below the basic amount ({$comp->basic_amount}). SARS may levy an under-estimation penalty if it proves too low — record why it is reasonable.";
            }
            if ($comp->provisional_number === 2 && ! $comp->lines()->where('section', 'profit')->whereNotNull('reason')->exists()) {
                $warnings[] = 'The second estimate should be supported by the year\'s trial balance.';
            }
        }

        $comp->forceFill(['validation' => ['errors' => $errors, 'warnings' => $warnings, 'checked_at' => now()->toIso8601String()]])->save();

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function recompute(TaxComputation $comp): void
    {
        $lines = $comp->lines()->get();
        $sum = fn (string $section) => array_reduce($lines->where('section', $section)->all(), fn ($c, $l) => bcadd($c, (string) $l->amount, 2), '0.00');

        $profit = $sum('profit');
        $addBacks = $sum('add_back');
        $deductions = $sum('deduction');
        $exempt = $sum('exempt');
        $allowances = $sum('allowance');
        $beforeLoss = bcsub(bcsub(bcsub(bcadd($profit, $addBacks, 2), $deductions, 2), $exempt, 2), $allowances, 2);

        // Assessed loss brought forward (CIT only), limited per config/tax.php.
        $lines->where('section', 'loss_set_off')->each->delete();
        $setOff = '0.00';
        $settings = app(\App\Modules\Finance\Tax\Support\TaxSettings::class);
        $capAmount = (string) $settings->get('assessed_loss_cap_amount', $comp->company_id);
        $capPercent = (string) $settings->get('assessed_loss_cap_percent', $comp->company_id);
        if ($comp->kind === 'cit' && bccomp($beforeLoss, '0', 2) > 0) {
            $broughtForward = $this->lossBroughtForward($comp);
            if (bccomp($broughtForward, '0', 2) > 0) {
                $capPct = bcdiv(bcmul($beforeLoss, $capPercent, 4), '100', 2);
                $cap = bccomp($capPct, $capAmount, 2) > 0 ? $capPct : $capAmount;
                $setOff = $this->min($this->min($broughtForward, $cap), $beforeLoss);
                $this->line($comp, 'loss_set_off', $setOff, "Assessed loss brought forward ({$broughtForward}), limited to the greater of R{$capAmount} or {$capPercent}% of taxable income",
                    'carry_forward', 900);
            }
        }
        $taxable = bcsub($beforeLoss, $setOff, 2);

        $rule = $this->rules->resolve('CIT', $comp->entity_id, $comp->year_end);
        $tax = '0.00';
        if ($rule && bccomp($taxable, '0', 2) > 0) {
            $tax = $this->calculator->calculate($rule, $taxable, false, ['date' => $comp->year_end->toDateString()])['tax'] ?? '0.00';
        }

        // Credits: CIT — provisional tax actually paid for the year; provisional — what earlier
        // periods of the same year were approved to pay.
        $lines->where('section', 'credit')->each->delete();
        $credits = '0.00';
        if ($comp->kind === 'cit') {
            $credits = $this->provisionalPaid($comp->entity_id, $comp->year_end);
            if (bccomp($credits, '0', 2) !== 0) {
                $this->line($comp, 'credit', $credits, 'Provisional tax paid for the year (payments allocated in Tax liabilities)', 'payments', 950);
            }
            $payable = bcsub($tax, $credits, 2);
        } else {
            $earlier = TaxComputation::query()->where('entity_id', $comp->entity_id)->where('kind', 'provisional')->where('status', 'approved')
                ->whereDate('year_end', $comp->year_end->toDateString())->where('provisional_number', '<', $comp->provisional_number)->get();
            $credits = array_reduce($earlier->all(), fn ($c, $e) => bcadd($c, (string) $e->amount_payable, 2), '0.00');
            if (bccomp($credits, '0', 2) !== 0) {
                $this->line($comp, 'credit', $credits, 'Provisional tax estimated for earlier periods of this year', 'payments', 950);
            }
            $share = $comp->provisional_number === 1 ? bcdiv(bcmul($tax, (string) $settings->get('provisional_first_share', $comp->company_id), 4), '100', 2) : $tax;
            $payable = bcsub($share, $credits, 2);
            if (bccomp($payable, '0', 2) < 0) {
                $payable = '0.00';
            }
        }

        $comp->forceFill([
            'accounting_profit' => $profit, 'add_backs' => $addBacks, 'deductions' => $deductions, 'exempt_income' => $exempt,
            'allowances' => $allowances, 'losses_set_off' => $setOff, 'taxable_income' => $taxable,
            'rate' => $rule?->rate, 'rule_version_id' => $rule?->id, 'tax' => $tax, 'credits' => $credits, 'amount_payable' => $payable,
        ])->save();
    }

    private function lossBroughtForward(TaxComputation $comp): string
    {
        $prior = TaxCarryForward::query()->where('entity_id', $comp->entity_id)->where('kind', 'assessed_loss')
            ->whereDate('year_end', '<', $comp->year_end->toDateString())->orderByDesc('year_end')->first();

        return $prior ? bcadd((string) $prior->closing, '0', 2) : '0.00';
    }

    private function provisionalPaid(?int $entityId, Carbon $yearEnd): string
    {
        $liabilityIds = TaxLiability::query()->where('entity_id', $entityId)->where('source', 'provisional')
            ->whereHas('period', fn ($q) => $q->whereDate('end_date', '<=', $yearEnd)->whereDate('end_date', '>', $yearEnd->copy()->subYear()))->pluck('id');

        return bcadd((string) TaxPaymentAllocation::query()->active()->whereIn('liability_id', $liabilityIds)->sum('amount'), '0', 2);
    }

    private function recordCarryForward(TaxComputation $comp): void
    {
        $opening = $this->lossBroughtForward($comp);
        $taxable = (string) $comp->taxable_income;
        // A loss this year adds to the balance; set-off used reduces it.
        $movement = bccomp($taxable, '0', 2) < 0 ? ltrim($taxable, '-') : bcmul((string) $comp->losses_set_off, '-1', 2);
        $closing = bcadd($opening, $movement, 2);

        TaxCarryForward::query()->updateOrCreate(
            ['entity_id' => $comp->entity_id, 'kind' => 'assessed_loss', 'year_end' => $comp->year_end->toDateString()],
            ['opening' => $opening, 'movement' => $movement, 'closing' => $closing, 'computation_id' => $comp->id,
                'source' => 'computation', 'created_by' => Auth::id(), 'note' => "From computation v{$comp->version}"]
        );
    }

    private function recordLiability(TaxComputation $comp): void
    {
        $payable = (string) $comp->amount_payable;
        // A new approved version replaces the expectation of the one before it.
        TaxLiability::query()->where('period_id', $comp->period_id)->whereIn('source', ['computation', 'provisional'])
            ->where('status', 'open')->where('paid_amount', 0)
            ->when($comp->kind === 'provisional', fn ($q) => $q->where('description', 'like', '%(' . $comp->provisional_number . ')%'))
            ->update(['status' => 'cancelled', 'notes' => DB::raw("COALESCE(notes, '') || ' Replaced by computation v{$comp->version}.'")]);

        if (bccomp($payable, '0', 2) === 0) {
            return;
        }
        $period = $comp->period;
        TaxLiability::create([
            'entity_id' => $comp->entity_id, 'company_id' => $comp->company_id, 'tax_type_id' => $period->tax_type_id,
            'registration_id' => $comp->registration_id, 'period_id' => $comp->period_id,
            'source' => $comp->kind === 'cit' ? 'computation' : 'provisional',
            'description' => $comp->kind === 'cit'
                ? 'Income tax ' . $comp->year_end->format('Y') . ' — balance per computation (before assessment)'
                : 'IRP6 ' . $comp->year_end->format('Y') . " ({$comp->provisional_number})",
            'original_amount' => $payable, 'due_date' => $period->payment_due_date,
            'status' => bccomp($payable, '0', 2) < 0 ? 'refund_due' : 'open', 'created_by' => Auth::id(),
        ]);
    }

    private function previous(TaxPeriod $period, string $kind, ?int $number): ?TaxComputation
    {
        return TaxComputation::query()->where('period_id', $period->id)->where('kind', $kind)
            ->when($number, fn ($q) => $q->where('provisional_number', $number))
            ->orderByDesc('version')->lockForUpdate()->first();
    }

    private function newVersion(TaxPeriod $period, string $kind, ?int $number, ?TaxComputation $previous, Carbon $start, Carbon $end, string $basis): TaxComputation
    {
        if ($previous && $previous->status !== 'superseded') {
            $previous->forceFill(['status' => 'superseded'])->save();
        }

        return TaxComputation::create([
            'entity_id' => $period->entity_id, 'company_id' => $period->company_id, 'registration_id' => $period->registration_id,
            'period_id' => $period->id, 'kind' => $kind, 'provisional_number' => $number, 'year_start' => $start->toDateString(),
            'year_end' => $end->toDateString(), 'version' => ($previous?->version ?? 0) + 1, 'supersedes_id' => $previous?->id,
            'status' => 'prepared', 'basis' => $basis, 'prepared_by' => Auth::id(), 'prepared_at' => now(),
        ]);
    }

    /** Manual adjustments survive re-preparation: the ledger is re-read, the judgement is kept. */
    private function carryManualLines(?TaxComputation $previous, TaxComputation $comp, int &$sort, array $except = []): void
    {
        if (! $previous) {
            return;
        }
        foreach ($previous->lines()->where('source', 'manual')->whereNotIn('section', $except)->get() as $l) {
            $this->line($comp, $l->section, (string) $l->amount, $l->description, 'manual', $sort++, null, null, $l->reason, $l->reference);
        }
    }

    private function line(TaxComputation $comp, string $section, string $amount, string $description, string $source, int $sort,
        ?int $accountId = null, ?string $code = null, ?string $reason = null, ?string $reference = null): TaxComputationLine
    {
        return TaxComputationLine::create(['company_id' => $comp->company_id, 'computation_id' => $comp->id, 'section' => $section,
            'account_id' => $accountId, 'account_code' => $code, 'description' => mb_substr($description, 0, 300), 'amount' => $amount,
            'source' => $source, 'reason' => $reason, 'reference' => $reference, 'created_by' => Auth::id(), 'sort' => $sort]);
    }

    /** The financial year a provisional period belongs to: the year ending on or after it. */
    private function yearFor(TaxPeriod $period): array
    {
        $month = (int) ($period->registration->year_end_month ?? 2);
        $end = $period->end_date->copy()->startOfMonth();
        while ($end->month !== $month) {
            $end->addMonthNoOverflow();
        }
        $end = $end->endOfMonth()->startOfDay();

        return [$end->copy()->subYear()->addDay(), $end];
    }

    private function min(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? $a : $b;
    }

    private function segregate(TaxComputation $comp, string $step, array $others): void
    {
        if (in_array(Auth::id(), array_filter($others), true)) {
            $this->audit->denied("income_tax.{$step}", $comp, 'Segregation of duties: the same person cannot prepare, review and approve.', ['period_id' => $comp->period_id]);
            throw new TaxControlException($step === 'review' ? 'You prepared this computation, so someone else has to review it.' : 'You prepared or reviewed this computation, so someone else has to approve it.');
        }
    }

    private function expect(TaxComputation $comp, array $statuses, string $message): void
    {
        if (! in_array($comp->status, $statuses, true)) {
            throw new TaxControlException($message);
        }
    }
}
