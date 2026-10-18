<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Models\TaxAuditEvent;
use App\Modules\Finance\Tax\Models\TaxComplianceDeadline;
use App\Modules\Finance\Tax\Models\TaxComputation;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPaymentAllocation;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxReconciliationItem;
use App\Modules\Finance\Tax\Models\TaxReturn;
use App\Modules\Finance\Tax\Models\TaxReturnLine;
use App\Modules\Finance\Tax\Models\TaxRuleVersion;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Support\TaxBusinesses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The standard tax reports (SRS §20.2), one dataset each, built from the same records the screens
 * show — never a second calculation. Each returns headers and rows; the caller adds the heading
 * block (§20.3: entity, period, generation time, report version) and the formula-safe export.
 *
 * Filters: business (entity), date range (on the period end or the record date) and tax type.
 * Tenant isolation applies throughout (company scope + row-level security).
 */
class TaxReportService
{
    public const VERSION = '1';

    /** key => [title, what it is for] */
    public const REPORTS = [
        'vat_register' => ['VAT transaction register', 'Every VAT line with its source document, code, rule and exceptions.'],
        'vat_schedule' => ['VAT return supporting schedule', 'The fields of each VAT201, calculated, adjusted and final.'],
        'vat_summary' => ['Input / output VAT summary', 'Output, input and net VAT per period.'],
        'vat_control_recon' => ['VAT control-account reconciliation', 'Each reconciliation run: totals, differences and sign-off.'],
        'payroll_summary' => ['PAYE, UIF and SDL summary', 'Payroll taxes per period and tax.'],
        'payroll_recon' => ['Payroll tax reconciliation', 'Payroll taxes against their ledger accounts, item by item.'],
        'cit_computation' => ['Corporate income tax computation', 'Each computation from accounting profit to tax, line by line.'],
        'provisional_schedule' => ['Provisional tax schedule', 'IRP6 estimates, tax and amounts payable per period.'],
        'liability_register' => ['Tax liability register', 'What is owed, paid and outstanding, and when it is due.'],
        'payment_register' => ['Tax payment and allocation register', 'Finance payments allocated to liabilities, including reversals.'],
        'return_status' => ['Tax return status report', 'Every return version: workflow, submission and payment status.'],
        'compliance' => ['Compliance calendar and overdue obligations', 'Filing and payment deadlines, owners and status.'],
        'adjustments' => ['Tax adjustments report', 'Manual adjustments to returns and computations, with reasons.'],
        'exceptions' => ['Unresolved tax exceptions', 'Register exceptions and reconciliation differences still open.'],
        'rule_history' => ['Tax rule change history', 'Every rule version: dates, rate, status and who approved it.'],
        'audit' => ['Tax audit report', 'The audit trail, with the chain hash of each event.'],
        'period_comparison' => ['Tax period comparison', 'Each tax\'s periods side by side.'],
        'evidence' => ['Document checklist and missing evidence', 'Each return\'s evidence items, attached or missing.'],
    ];

    private ?int $entityId = null;

    private ?string $from = null;

    private ?string $to = null;

    private ?string $taxType = null;

    public function __construct(private readonly TaxBusinesses $businesses)
    {
    }

    public function filter(?int $entityId, ?string $from, ?string $to, ?string $taxType): self
    {
        $this->entityId = $entityId;
        $this->from = $from ? Carbon::parse($from)->toDateString() : null;
        $this->to = $to ? Carbon::parse($to)->toDateString() : null;
        $this->taxType = $taxType ? strtoupper($taxType) : null;

        return $this;
    }

    /** @return array{0: list<string>, 1: iterable<array<int, mixed>>} */
    public function dataset(string $key, ?int $limit = null): array
    {
        return match ($key) {
            'vat_register' => $this->vatRegister($limit),
            'vat_schedule' => $this->returnSchedule('VAT201', $limit),
            'vat_summary' => $this->vatSummary(),
            'vat_control_recon' => $this->reconciliations('vat_register_to_gl', $limit),
            'payroll_summary' => $this->payrollSummary(),
            'payroll_recon' => $this->reconciliationItems('payroll_to_gl', $limit),
            'cit_computation' => $this->computationLines('cit', $limit),
            'provisional_schedule' => $this->provisional($limit),
            'liability_register' => $this->liabilities($limit),
            'payment_register' => $this->payments($limit),
            'return_status' => $this->returnStatus($limit),
            'compliance' => $this->compliance($limit),
            'adjustments' => $this->adjustments($limit),
            'exceptions' => $this->exceptions($limit),
            'rule_history' => $this->ruleHistory($limit),
            'audit' => $this->audit($limit),
            'period_comparison' => $this->periodComparison(),
            'evidence' => $this->evidence($limit),
            default => [[], []],
        };
    }

    /** The heading block every export carries (§20.3). */
    public function meta(string $key): array
    {
        return [
            'Report' => self::REPORTS[$key][0] ?? $key,
            'Business' => $this->entityId ? $this->businesses->name($this->entityId) : 'All businesses',
            'Period' => ($this->from ?? 'start') . ' to ' . ($this->to ?? 'today'),
            'Tax' => $this->taxType ?? 'All',
            'Generated' => now()->toDateTimeString(),
            'Report version' => self::VERSION,
            'Currency' => 'ZAR',
        ];
    }

    // ── Datasets ─────────────────────────────────────────────────────────────

    private function vatRegister(?int $limit): array
    {
        $q = TaxTransaction::query()->current()->forBusiness($this->entityId)->whereHas('taxType', fn ($t) => $t->where('code', 'VAT'))
            ->when($this->from, fn ($q) => $q->whereDate('document_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('document_date', '<=', $this->to))
            ->with('period:id,label')->orderBy('document_date')->orderBy('id');

        return [['Period', 'Date', 'Type', 'Document', 'Direction', 'Party', 'Party VAT no.', 'Code', 'Treatment', 'VAT201 field', 'Taxable', 'VAT', 'Rule says', 'Status', 'Exceptions', 'Source'],
            $this->rows($q, $limit, fn ($t) => [$t->period?->label, $t->document_date->toDateString(), $t->document_type, $t->document_number, $t->direction,
                $t->party_name, $t->party_vat_number, $t->tax_code, $t->treatment, $t->return_field, (string) $t->taxable_amount, (string) $t->tax_amount,
                $t->calculated_tax === null ? null : (string) $t->calculated_tax, $t->status,
                collect($t->exceptions ?? [])->map(fn ($e) => $e['code'] . (isset($e['resolved_at']) ? '*' : ''))->implode('; '), $t->source_key])];
    }

    private function returnSchedule(string $form, ?int $limit): array
    {
        $q = TaxReturnLine::query()->join('tax_returns as r', 'r.id', '=', 'tax_return_lines.return_id')
            ->join('tax_return_forms as f', 'f.id', '=', 'r.form_id')->join('tax_periods as p', 'p.id', '=', 'r.period_id')
            ->where('f.code', $form)->where('r.status', '!=', 'superseded')
            ->when($this->entityId, fn ($q) => $q->where('r.entity_id', $this->entityId))
            ->when($this->from, fn ($q) => $q->whereDate('p.end_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('p.end_date', '<=', $this->to))
            ->orderBy('p.end_date')->orderBy('tax_return_lines.sort')
            ->select('tax_return_lines.*', 'r.reference', 'r.status as return_status', 'p.label as period_label');

        return [['Period', 'Return', 'Status', 'Field', 'Description', 'Calculated', 'Adjustment', 'Final', 'Adjustment reason', 'Source lines'],
            $this->rows($q, $limit, fn ($l) => [$l->period_label, $l->reference, $l->return_status, $l->field, $l->label, (string) $l->calculated,
                (string) $l->adjustment, (string) $l->final, $l->adjustment_reason, $l->source_count])];
    }

    private function vatSummary(): array
    {
        $periods = $this->periods('VAT');
        // One grouped query for every period, not two sums per period.
        $sums = TaxTransaction::query()->current()->whereIn('period_id', $periods->pluck('id'))->where('status', '!=', 'excluded')
            ->groupBy('period_id', 'direction')->selectRaw('period_id, direction, SUM(tax_amount) as total')->get()
            ->mapWithKeys(fn ($r) => ["{$r->period_id}.{$r->direction}" => (string) $r->total]);
        $rows = $periods->map(function (TaxPeriod $p) use ($sums) {
            $out = bcadd($sums["{$p->id}.output"] ?? '0', '0', 2);
            $in = bcadd($sums["{$p->id}.input"] ?? '0', '0', 2);

            return [$p->label, $this->businesses->name($p->entity_id), $p->status, $out, $in, bcsub($out, $in, 2)];
        });

        return [['Period', 'Business', 'Status', 'Output VAT', 'Input VAT', 'Net payable / (refundable)'], $rows->all()];
    }

    private function reconciliations(string $type, ?int $limit): array
    {
        $q = TaxReconciliation::query()->forBusiness($this->entityId)->where('type', $type)->with('period:id,label,end_date')
            ->whereHas('period', fn ($p) => $p->when($this->from, fn ($q) => $q->whereDate('end_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('end_date', '<=', $this->to)))
            ->orderBy('period_id')->orderBy('version');

        return [['Period', 'Version', 'Status', 'Cutoff', 'Register', 'Ledger', 'Difference', 'Tolerance', 'Matched', 'Unmatched', 'Exceptions', 'Signed off at'],
            $this->rows($q, $limit, fn ($r) => [$r->period?->label, $r->version, $r->status, $r->cutoff_at?->toDateTimeString(), (string) $r->source_total,
                (string) $r->target_total, (string) $r->difference, (string) $r->tolerance, $r->matched_count, $r->unmatched_count, $r->exception_count,
                $r->signed_off_at?->toDateTimeString()])];
    }

    private function reconciliationItems(string $type, ?int $limit): array
    {
        $q = TaxReconciliationItem::query()->join('tax_reconciliations as r', 'r.id', '=', 'tax_reconciliation_items.reconciliation_id')
            ->join('tax_periods as p', 'p.id', '=', 'r.period_id')->where('r.type', $type)
            ->when($this->entityId, fn ($q) => $q->where('r.entity_id', $this->entityId))
            ->when($this->from, fn ($q) => $q->whereDate('p.end_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('p.end_date', '<=', $this->to))
            ->orderBy('p.end_date')->orderBy('r.version')->orderBy('tax_reconciliation_items.id')
            ->select('tax_reconciliation_items.*', 'p.label as period_label', 'r.version', 'r.status as recon_status');

        return [['Period', 'Run', 'Run status', 'Item', 'Register', 'Ledger', 'Difference', 'Match', 'Kind', 'Explanation'],
            $this->rows($q, $limit, fn ($i) => [$i->period_label, $i->version, $i->recon_status, $i->reference, (string) $i->source_amount, (string) $i->target_amount,
                (string) $i->difference, $i->match_state, $i->difference_class, $i->explanation])];
    }

    private function payrollSummary(): array
    {
        $periods = $this->periods('PAYE');
        $all = TaxTransaction::query()->current()->whereIn('period_id', $periods->pluck('id'))->where('source_module', 'payroll')->where('status', '!=', 'excluded')
            ->join('tax_types as t', 't.id', '=', 'tax_transactions.tax_type_id')->groupBy('tax_transactions.period_id', 't.code')
            ->selectRaw('tax_transactions.period_id, t.code, SUM(tax_amount) as total, COUNT(DISTINCT party_id) as people')->get()->groupBy('period_id');
        $rows = $periods->map(function (TaxPeriod $p) use ($all) {
            $by = ($all[$p->id] ?? collect())->keyBy('code');
            $v = fn ($c) => bcadd((string) ($by[$c]->total ?? '0'), '0', 2);

            return [$p->label, $this->businesses->name($p->entity_id), $p->status, (int) ($by['PAYE']->people ?? 0), $v('PAYE'), $v('UIF'), $v('SDL'), $v('ETI'),
                bcsub(bcadd(bcadd($v('PAYE'), $v('UIF'), 2), $v('SDL'), 2), $v('ETI'), 2)];
        });

        return [['Period', 'Business', 'Status', 'Employees', 'PAYE', 'UIF', 'SDL', 'ETI', 'EMP201 total'], $rows->all()];
    }

    private function computationLines(string $kind, ?int $limit): array
    {
        $q = DB::table('tax_computation_lines as l')->join('tax_computations as c', 'c.id', '=', 'l.computation_id')
            ->where('c.kind', $kind)->where('c.status', '!=', 'superseded')
            ->when($this->entityId, fn ($q) => $q->where('c.entity_id', $this->entityId))
            ->when($this->from, fn ($q) => $q->whereDate('c.year_end', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('c.year_end', '<=', $this->to))
            ->orderBy('c.year_end')->orderBy('l.sort')->orderBy('l.id')
            ->select('c.year_end', 'c.version', 'c.status', 'c.taxable_income', 'c.tax', 'l.section', 'l.account_code', 'l.description', 'l.amount', 'l.source', 'l.reference', 'l.reason');

        return [['Year end', 'Version', 'Status', 'Section', 'Account', 'Description', 'Amount', 'Source', 'Section of the Act', 'Reason', 'Taxable income', 'Tax'],
            $this->rows($q, $limit, fn ($l) => [$l->year_end, $l->version, $l->status, $l->section, $l->account_code, $l->description, (string) $l->amount,
                $l->source, $l->reference, $l->reason, (string) $l->taxable_income, (string) $l->tax])];
    }

    private function provisional(?int $limit): array
    {
        $q = TaxComputation::query()->forBusiness($this->entityId)->where('kind', 'provisional')->where('status', '!=', 'superseded')
            ->when($this->from, fn ($q) => $q->whereDate('year_end', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('year_end', '<=', $this->to))
            ->with('period:id,label,payment_due_date')->orderBy('year_end')->orderBy('provisional_number');

        return [['Year end', 'Period', 'IRP6', 'Version', 'Status', 'Basis', 'Estimated taxable income', 'Basic amount', 'Tax on estimate', 'Earlier periods', 'Payable', 'Due'],
            $this->rows($q, $limit, fn ($c) => [$c->year_end->toDateString(), $c->period?->label, $c->provisional_number, $c->version, $c->status, $c->basis,
                (string) $c->taxable_income, $c->basic_amount === null ? null : (string) $c->basic_amount, (string) $c->tax, (string) $c->credits,
                (string) $c->amount_payable, $c->period?->payment_due_date?->toDateString()])];
    }

    private function liabilities(?int $limit): array
    {
        $q = TaxLiability::query()->forBusiness($this->entityId)->with(['taxType:id,code', 'period:id,label'])
            ->when($this->taxType, fn ($q) => $q->whereHas('taxType', fn ($t) => $t->where('code', $this->taxType)))
            ->when($this->from, fn ($q) => $q->whereDate('due_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('due_date', '<=', $this->to))
            ->orderBy('due_date');

        return [['Tax', 'Business', 'Period', 'Description', 'Source', 'Original', 'Adjustments', 'Paid', 'Outstanding', 'Due', 'Status'],
            $this->rows($q, $limit, fn ($l) => [$l->taxType?->code, $this->businesses->name($l->entity_id), $l->period?->label, $l->description, $l->source,
                (string) $l->original_amount, (string) $l->adjustments, (string) $l->paid_amount, $l->outstanding(), $l->due_date?->toDateString(), $l->status])];
    }

    private function payments(?int $limit): array
    {
        $q = TaxPaymentAllocation::query()->join('tax_liabilities as l', 'l.id', '=', 'tax_payment_allocations.liability_id')
            ->leftJoin('fin_payments as p', 'p.id', '=', 'tax_payment_allocations.fin_payment_id')
            ->when($this->entityId, fn ($q) => $q->where('l.entity_id', $this->entityId))
            ->when($this->from, fn ($q) => $q->whereDate('tax_payment_allocations.allocated_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('tax_payment_allocations.allocated_at', '<=', $this->to))
            ->orderBy('tax_payment_allocations.allocated_at')
            ->select('tax_payment_allocations.*', 'l.description as liability', 'p.number as payment_number', 'p.payment_date', 'p.reference as payment_reference', 'p.amount as payment_amount');

        return [['Allocated', 'Liability', 'Payment', 'Payment date', 'Payment reference', 'Payment amount', 'Allocated amount', 'Note', 'Reversed', 'Reversal reason'],
            $this->rows($q, $limit, fn ($a) => [$a->allocated_at?->toDateString(), $a->liability, $a->payment_number, $a->payment_date, $a->payment_reference,
                (string) $a->payment_amount, (string) $a->amount, $a->note, $a->reversed_at?->toDateString(), $a->reversal_reason])];
    }

    private function returnStatus(?int $limit): array
    {
        $q = TaxReturn::query()->forBusiness($this->entityId)->with(['period:id,label,end_date', 'form:id,code'])
            ->whereHas('period', fn ($p) => $p->when($this->from, fn ($q) => $q->whereDate('end_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('end_date', '<=', $this->to)))
            ->orderBy('period_id')->orderBy('version');

        return [['Return', 'Form', 'Period', 'Version', 'Purpose', 'Status', 'Payable', 'Due', 'Submission', 'SARS reference', 'Submitted', 'Payment'],
            $this->rows($q, $limit, fn ($r) => [$r->reference, $r->form?->code, $r->period?->label, $r->version, $r->purpose, $r->status, (string) $r->amount_payable,
                $r->due_date?->toDateString(), $r->submission_status, $r->submission_reference, $r->submitted_at?->toDateTimeString(), $r->payment_status])];
    }

    private function compliance(?int $limit): array
    {
        $q = TaxComplianceDeadline::query()->forBusiness($this->entityId)->with('taxType:id,code')
            ->when($this->from, fn ($q) => $q->whereDate('due_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('due_date', '<=', $this->to))
            ->orderBy('due_date');
        $names = DB::table('users')->where('company_id', Auth::user()?->company_id)->pluck('email', 'id');

        return [['Due', 'Tax', 'What', 'Kind', 'Status', 'Days late', 'Owner', 'Completed', 'Resolution'],
            $this->rows($q, $limit, fn ($d) => [$d->due_date->toDateString(), $d->taxType?->code, $d->title, $d->kind, $d->status,
                $d->status !== 'done' && $d->due_date->lt(today()) ? (int) $d->due_date->diffInDays(today()) : null,
                $names[$d->owner_id] ?? null, $d->completed_at?->toDateString(), $d->resolution_note])];
    }

    private function adjustments(?int $limit): array
    {
        $returns = TaxReturnLine::query()->join('tax_returns as r', 'r.id', '=', 'tax_return_lines.return_id')->where('tax_return_lines.adjustment', '!=', 0)
            ->when($this->entityId, fn ($q) => $q->where('r.entity_id', $this->entityId))
            ->get(['r.reference', 'tax_return_lines.field', 'tax_return_lines.label', 'tax_return_lines.adjustment', 'tax_return_lines.adjustment_reason', 'tax_return_lines.adjusted_at'])
            ->map(fn ($l) => [$l->adjusted_at, 'Return', $l->reference, "Field {$l->field} {$l->label}", (string) $l->adjustment, $l->adjustment_reason]);
        $computations = DB::table('tax_computation_lines as l')->join('tax_computations as c', 'c.id', '=', 'l.computation_id')
            ->where('l.source', 'manual')->where('c.status', '!=', 'superseded')
            ->when($this->entityId, fn ($q) => $q->where('c.entity_id', $this->entityId))
            ->get(['l.created_at', 'c.kind', 'c.year_end', 'l.section', 'l.description', 'l.amount', 'l.reason'])
            ->map(fn ($l) => [$l->created_at, $l->kind === 'cit' ? 'Income tax' : 'Provisional', $l->year_end, "{$l->section}: {$l->description}", (string) $l->amount, $l->reason]);
        $register = TaxAuditEvent::query()->whereIn('action', ['vat_register.classify', 'vat_register.accept'])
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->get()->map(fn ($e) => [$e->created_at, 'Register', $e->context['source_key'] ?? "#{$e->subject_id}", $e->action, null, $e->reason]);

        $all = $returns->concat($computations)->concat($register)->sortBy(fn ($r) => (string) $r[0])->values()
            ->map(fn ($r) => [is_object($r[0]) ? $r[0]->toDateTimeString() : (string) $r[0], ...array_slice($r, 1)]);

        return [['When', 'Where', 'Record', 'What', 'Amount', 'Reason'], $limit ? $all->take($limit)->all() : $all->all()];
    }

    private function exceptions(?int $limit): array
    {
        $register = TaxTransaction::query()->current()->forBusiness($this->entityId)->where('has_exception', true)->with(['period:id,label', 'taxType:id,code'])->get()
            ->flatMap(fn ($t) => collect($t->exceptions ?? [])->filter(fn ($e) => ($e['blocking'] ?? false) && empty($e['resolved_at']))
                ->map(fn ($e) => [$t->taxType?->code, $t->period?->label, 'Register', $t->document_number, $e['code'], $e['message'], null]));
        $recon = TaxReconciliationItem::query()->join('tax_reconciliations as r', 'r.id', '=', 'tax_reconciliation_items.reconciliation_id')
            ->join('tax_periods as p', 'p.id', '=', 'r.period_id')->where('r.status', 'completed')
            ->where('tax_reconciliation_items.match_state', '!=', 'matched')->whereNull('tax_reconciliation_items.explanation')
            ->when($this->entityId, fn ($q) => $q->where('r.entity_id', $this->entityId))
            ->get(['p.label', 'r.type', 'tax_reconciliation_items.reference', 'tax_reconciliation_items.difference_class', 'tax_reconciliation_items.description', 'tax_reconciliation_items.difference'])
            ->map(fn ($i) => [$i->type === 'payroll_to_gl' ? 'PAYE' : 'VAT', $i->label, 'Reconciliation', $i->reference, $i->difference_class, $i->description, (string) $i->difference]);
        $all = $register->concat($recon)->values();

        return [['Tax', 'Period', 'Where', 'Record', 'Kind', 'Detail', 'Difference'], $limit ? $all->take($limit)->all() : $all->all()];
    }

    private function ruleHistory(?int $limit): array
    {
        $q = TaxRuleVersion::query()->with('taxType:id,code')->orderBy('tax_code')->orderBy('version');
        $names = DB::table('users')->where('company_id', Auth::user()?->company_id)->pluck('email', 'id');

        return [['Tax', 'Code', 'Version', 'Applies to', 'Treatment', 'Rate', 'From', 'To', 'Status', 'Drafted by', 'Approved by', 'Approved at', 'Reason', 'Legal reference'],
            $this->rows($q, $limit, fn ($r) => [$r->taxType?->code, $r->tax_code, $r->version, $r->entity_id ? $this->businesses->name($r->entity_id) : 'All businesses',
                $r->treatment, rtrim(rtrim((string) $r->rate, '0'), '.'), $r->effective_from?->toDateString(), $r->effective_to?->toDateString(), $r->status,
                $names[$r->created_by] ?? null, $names[$r->approved_by] ?? null, $r->approved_at?->toDateTimeString(), $r->change_reason, $r->legal_reference])];
    }

    private function audit(?int $limit): array
    {
        $q = TaxAuditEvent::query()
            ->when($this->entityId, fn ($q) => $q->where(fn ($w) => $w->where('entity_id', $this->entityId)->orWhereNull('entity_id')))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->orderBy('id');

        return [['Event', 'When', 'Who', 'Action', 'Record', 'Tax', 'Outcome', 'Reason', 'Correlation', 'Hash'],
            $this->rows($q, $limit, fn ($e) => [$e->id, $e->created_at?->toDateTimeString(), $e->actor_name, $e->action, $e->subject_type . ($e->subject_id ? " #{$e->subject_id}" : ''),
                $e->tax_type, $e->outcome, $e->reason, $e->correlation_id, $e->hash])];
    }

    private function periodComparison(): array
    {
        $rows = TaxPeriod::query()->forBusiness($this->entityId)->with('taxType:id,code')
            ->when($this->taxType, fn ($q) => $q->whereHas('taxType', fn ($t) => $t->where('code', $this->taxType)))
            ->when($this->from, fn ($q) => $q->whereDate('end_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('end_date', '<=', $this->to))
            ->orderBy('tax_type_id')->orderBy('end_date')->get();
        // Returns, register counts and liabilities for all the periods at once (three queries, not three per period).
        $ids = $rows->pluck('id');
        $returns = TaxReturn::query()->whereIn('period_id', $ids)->where('status', '!=', 'superseded')->orderByDesc('version')->get()->unique('period_id')->keyBy('period_id');
        $counts = TaxTransaction::query()->current()->whereIn('period_id', $ids)->where('status', '!=', 'excluded')
            ->groupBy('period_id')->selectRaw('period_id, COUNT(*) as n')->pluck('n', 'period_id');
        $liabilities = TaxLiability::query()->whereIn('period_id', $ids)->where('status', '!=', 'cancelled')->get()->groupBy('period_id');
        $rows = $rows
            ->map(function (TaxPeriod $p) use ($returns, $counts, $liabilities) {
                $return = $returns[$p->id] ?? null;
                $lines = (int) ($counts[$p->id] ?? 0);
                $liability = $liabilities[$p->id] ?? collect();

                return [$p->taxType?->code, $p->label, $this->businesses->name($p->entity_id), $p->status, $lines,
                    $return ? (string) $return->amount_payable : null, $return?->submission_status,
                    array_reduce($liability->all(), fn ($c, $l) => bcadd($c, (string) $l->paid_amount, 2), '0.00'),
                    array_reduce($liability->all(), fn ($c, $l) => bcadd($c, $l->outstanding(), 2), '0.00')];
            });

        return [['Tax', 'Period', 'Business', 'Status', 'Register lines', 'Return payable', 'Submission', 'Paid', 'Outstanding'], $rows->all()];
    }

    private function evidence(?int $limit): array
    {
        $returns = TaxReturn::query()->forBusiness($this->entityId)->where('status', '!=', 'superseded')->with(['form', 'period:id,label'])->orderBy('period_id')
            ->when($limit, fn ($q) => $q->limit($limit))->get();
        $svc = app(TaxReturnService::class);
        $rows = [];
        foreach ($returns as $r) {
            foreach ($svc->evidence($r) as $e) {
                $rows[] = [$r->reference, $r->period?->label, $r->status, $e['label'], $e['required_for'] ? 'Required to ' . $e['required_for'] : 'Optional',
                    $e['documents'] ? 'Attached' : 'Missing', implode('; ', array_column($e['documents'], 'title'))];
            }
        }

        return [['Return', 'Period', 'Status', 'Evidence', 'Requirement', 'State', 'Documents'], $rows];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function periods(string $code)
    {
        return TaxPeriod::query()->forBusiness($this->entityId)->whereHas('taxType', fn ($q) => $q->where('code', $code))
            ->when($this->from, fn ($q) => $q->whereDate('end_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('end_date', '<=', $this->to))
            ->orderBy('end_date')->get();
    }

    /** Stream rows for an export (no limit) or take a preview. */
    private function rows($query, ?int $limit, callable $map): iterable
    {
        if ($limit) {
            return $query->limit($limit)->get()->map($map)->all();
        }

        // cursor() would drop the query's eager loads and fetch each row's relations one by one;
        // lazy() streams in chunks and eager-loads each chunk.
        if ($query instanceof \Illuminate\Database\Eloquent\Builder && ! $query->getQuery()->orders) {
            $query->orderBy($query->getModel()->getQualifiedKeyName());
        }

        return (function () use ($query, $map) {
            foreach ($query instanceof \Illuminate\Database\Eloquent\Builder ? $query->lazy(500) : $query->cursor() as $row) {
                yield $map($row);
            }
        })();
    }
}
