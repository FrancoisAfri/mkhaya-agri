<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Accounting\Scopes\EntityScope;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Models\TaxType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The VAT register (§6): one row per VAT-relevant source line, built from Finance's posted documents.
 *
 * Sources (TAX-VAT-001): posted AR and AP invoices and credit notes (fin_invoices / fin_invoice_lines,
 * both directions) and posted expenses that carry VAT (fin_expenses). Only documents with a journal
 * are read: an unposted draft is not a supply yet, and the GL is the record (§2.2).
 *
 * Idempotent (TAX-VAT-008, TAX-007): every row has a source key — module:table:id:line — and a hash
 * of the fields that matter. Refreshing again changes nothing unless the source did.
 *
 * Corrections keep the original (TAX-VAT-005):
 *   - source changed, its period still in preparation → a new version; the old row stays, superseded.
 *   - source changed, its period already submitted/closed/locked → the old row is untouched (that
 *     return must stay reproducible) and the difference is written as a delta row in the period
 *     being prepared, flagged until a preparer accepts it (§24 "source transaction changed").
 *
 * Classification never edits the source document (TAX-RET-002): a preparer classifies the register
 * line, with a reason, and the line remembers that its code came from a person.
 */
class VatRegisterService
{
    private const TAX_TOLERANCE = '0.01';

    public function __construct(
        private readonly TaxRuleService $rules,
        private readonly TaxCalculator $calculator,
        private readonly TaxPeriodService $periods,
        private readonly TaxAudit $audit,
    ) {
    }

    /**
     * Bring the register for a period up to date with its sources.
     *
     * @return array{created:int, superseded:int, deltas:int, unchanged:int, exceptions:int}
     */
    public function refresh(TaxPeriod $period): array
    {
        if ($period->taxType?->code !== 'VAT') {
            throw new TaxControlException('Only a VAT period has a VAT register.');
        }
        $this->periods->assertProcessable($period);

        $stats = ['created' => 0, 'superseded' => 0, 'deltas' => 0, 'late' => 0, 'unchanged' => 0, 'exceptions' => 0];
        $vat = TaxType::byCode('VAT');

        DB::transaction(function () use ($period, $vat, &$stats) {
            $lines = $this->sourceLines($period->entity_id, $period->start_date, $period->end_date);
            $seenKeys = [];

            foreach ($lines as $line) {
                $seenKeys[] = $line['source_key'];
                $this->upsert($line, $period, $vat, $stats);
            }

            // A line that was in the register for this period and has gone from its source (the
            // invoice was voided or the line removed) is excluded the same way a change is recorded.
            $vanished = TaxTransaction::query()->withoutGlobalScope(EntityScope::class)->current()->where('period_id', $period->id)
                ->whereIn('source_module', ['finance_ar', 'finance_ap', 'finance_expense'])
                ->where('status', '!=', 'excluded')
                ->where('document_type', '!=', 'adjustment')     // deltas are not source lines of this period
                ->whereNotIn('source_key', $seenKeys ?: ['-'])
                ->get();
            foreach ($vanished as $row) {
                $gone = array_merge($row->only($this->carried()), [
                    'source_hash' => hash('sha256', 'excluded:' . $row->source_key), 'status' => 'excluded',
                    'taxable_amount' => '0.00', 'tax_amount' => '0.00', 'calculated_tax' => '0.00', 'difference' => '0.00',
                    'exceptions' => [], 'has_exception' => false,
                ]);
                $this->replace($row, $gone, $period, $stats, $row->period_id);
            }

            $this->lateChanges($period, $vat, $stats);

            $this->audit->record('vat_register.refreshed', $period, ['period_id' => $period->id, 'tax_type' => 'VAT', 'after' => $stats]);
        });

        $stats['exceptions'] = TaxTransaction::query()->withoutGlobalScope(EntityScope::class)->current()->where('period_id', $period->id)->where('has_exception', true)->count();

        if ($period->status === 'open') {
            $this->periods->transition($period, 'in_preparation', 'VAT register built.');
        }

        return $stats;
    }

    /**
     * TAX-VAT-007 / exception resolution: classify a line's tax code, or accept an exception, with
     * a reason. The source document is not touched.
     */
    public function resolve(TaxTransaction $row, string $action, string $reason, ?string $taxCode = null): TaxTransaction
    {
        if (! $row->is_current) {
            throw new TaxControlException('This line has been superseded; resolve the current version.');
        }
        $this->periods->assertProcessable($row->period);
        if (mb_strlen(trim($reason)) < 5) {
            throw new TaxControlException('A reason is required — it stays with the line on the audit trail.');
        }

        return DB::transaction(function () use ($row, $action, $reason, $taxCode) {
            $before = $row->only(['tax_code', 'tax_code_source', 'exceptions', 'has_exception', 'calculated_tax', 'rule_version_id']);

            if ($action === 'classify') {
                $rule = $taxCode ? $this->rules->resolve($taxCode, $row->entity_id, $row->document_date) : null;
                if (! $rule) {
                    throw new TaxControlException("No published rule for {$taxCode} is in force on " . $row->document_date->toDateString() . '.');
                }
                $row->tax_code = $rule->tax_code;
                $row->tax_code_source = 'classified';
                $this->applyRule($row, $rule, (string) $row->taxable_amount, (string) $row->tax_amount);
                // Exceptions the classification answers go; anything else stays to be dealt with.
                $row->exceptions = array_values(array_filter($row->exceptions ?? [], fn ($e) => ! in_array($e['code'], ['missing_code', 'unknown_code', 'no_rule', 'inferred_code'], true)));
                $this->recheck($row);
            } elseif ($action === 'accept') {
                $row->exceptions = array_map(fn ($e) => ($e['resolved_at'] ?? null) ? $e : $e + [
                    'resolved_at' => now()->toIso8601String(), 'resolved_by' => Auth::id(), 'resolution' => $reason,
                ], $row->exceptions ?? []);
            } else {
                throw new TaxControlException('Unknown action.');
            }

            $row->has_exception = $this->hasBlocking($row->exceptions ?? []);
            $row->status = $row->status === 'excluded' ? 'excluded' : 'adjusted';
            $row->save();

            $this->audit->record('vat_register.' . $action, $row, ['period_id' => $row->period_id, 'tax_type' => 'VAT',
                'rule_version_id' => $row->rule_version_id, 'reason' => $reason, 'before' => $before,
                'after' => $row->only(['tax_code', 'tax_code_source', 'exceptions', 'has_exception', 'calculated_tax', 'rule_version_id']),
                'context' => ['source_key' => $row->source_key]]);

            return $row;
        });
    }

    /**
     * Totals by VAT201 field and treatment (§6.2 return supporting schedule). Field mapping is the
     * rule version's `return_field`, so it is versioned with the rule; lines whose rule names none
     * fall back to the treatment's usual field and are marked as such.
     *
     * @return array{fields: list<array{field:string, label:string, taxable:string, tax:string, lines:int, mapped:bool}>, output_tax:string, input_tax:string, net:string}
     */
    public function summary(TaxPeriod $period): array
    {
        $rows = TaxTransaction::query()->withoutGlobalScope(EntityScope::class)->current()->where('period_id', $period->id)->where('status', '!=', 'excluded')->get();
        $fields = [];
        $output = '0';
        $input = '0';

        foreach ($rows as $r) {
            [$field, $mapped] = $r->return_field ? [$r->return_field, true] : [$this->defaultField($r->direction, $r->treatment), false];
            $fields[$field] ??= ['field' => $field, 'label' => $this->fieldLabel($field), 'taxable' => '0', 'tax' => '0', 'lines' => 0, 'mapped' => true];
            $fields[$field]['taxable'] = bcadd($fields[$field]['taxable'], (string) $r->taxable_amount, 2);
            $fields[$field]['tax'] = bcadd($fields[$field]['tax'], (string) $r->tax_amount, 2);
            $fields[$field]['lines']++;
            $fields[$field]['mapped'] = $fields[$field]['mapped'] && $mapped;

            if ($r->direction === 'output') {
                $output = bcadd($output, (string) $r->tax_amount, 2);
            } else {
                $input = bcadd($input, (string) $r->tax_amount, 2);
            }
        }

        uksort($fields, fn ($a, $b) => strnatcmp((string) $a, (string) $b));

        return ['fields' => array_values($fields), 'output_tax' => bcadd($output, '0', 2), 'input_tax' => bcadd($input, '0', 2),
            'net' => bcsub($output, $input, 2)];
    }

    /**
     * TAX-PER-009 / §24: what changed in periods already finalised, reported here.
     *
     * A submitted period is never refreshed — its return must stay reproducible — so its sources
     * are re-read from the open period instead. A changed or voided document becomes a delta in this
     * period; a document dated in a finalised period that was never in the register
     * (posted late) is recorded here, flagged until a preparer accepts it.
     */
    private function lateChanges(TaxPeriod $period, TaxType $vat, array &$stats): void
    {
        $finalised = TaxPeriod::query()->withoutGlobalScope(EntityScope::class)
            ->where('registration_id', $period->registration_id)
            ->whereDate('end_date', '<', $period->start_date->toDateString())
            ->whereNotIn('status', ['open', 'in_preparation', 'rejected'])
            ->orderBy('start_date')->get();

        foreach ($finalised as $old) {
            $seen = [];
            foreach ($this->sourceLines($period->entity_id, $old->start_date, $old->end_date) as $line) {
                $seen[] = $line['source_key'];
                $hash = $this->hashOf($line);
                $current = TaxTransaction::query()->withoutGlobalScope(EntityScope::class)->current()
                    ->where('source_key', $line['source_key'])->orderByDesc('version')->first();

                if ($current && $current->source_hash === $hash) {
                    continue;
                }

                $attributes = $this->build($line, $hash, $vat, $period->entity_id);
                if ($current) {
                    $this->replace($current, $attributes, $period, $stats, $current->period_id);

                    continue;
                }

                // Never registered: posted after its period was finalised.
                $attributes['exceptions'] = array_merge($attributes['exceptions'] ?? [], [$this->exception('late_transaction',
                    "Dated in {$old->label}, which is already " . str_replace('_', ' ', $old->status) . '. Accept it to report it in this period, or amend that return.', true)]);
                $attributes['has_exception'] = true;
                TaxTransaction::create($attributes + ['entity_id' => $period->entity_id, 'company_id' => $period->company_id,
                    'period_id' => $period->id, 'version' => 1, 'is_current' => true]);
                $stats['late']++;
            }

            // A document voided after its period was finalised: the reversal belongs here too.
            $gone = TaxTransaction::query()->withoutGlobalScope(EntityScope::class)->current()->where('period_id', $old->id)
                ->where('status', '!=', 'excluded')->where('document_type', '!=', 'adjustment')
                ->whereNotIn('source_key', $seen ?: ['-'])->get();
            foreach ($gone as $row) {
                $this->replace($row, array_merge($row->only($this->carried()), [
                    'source_hash' => hash('sha256', 'excluded:' . $row->source_key), 'status' => 'recorded',
                    'taxable_amount' => '0.00', 'tax_amount' => '0.00', 'calculated_tax' => '0.00', 'difference' => '0.00',
                    'exceptions' => [], 'has_exception' => false,
                ]), $period, $stats, $row->period_id);
            }
        }
    }

    // ── Sources ──────────────────────────────────────────────────────────────

    /**
     * Every VAT-relevant source line for an entity and date range, normalised.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sourceLines(?int $entityId, Carbon $from, Carbon $to): Collection
    {
        $base = config('finance.base_currency', 'ZAR');

        $invoiceLines = DB::table('fin_invoice_lines as l')
            ->join('fin_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->leftJoin('fin_parties as p', 'p.id', '=', 'i.party_id')
            ->whereNotNull('i.journal_id')
            ->where('i.status', '!=', 'void')
            ->when($entityId, fn ($q) => $q->where('i.entity_id', $entityId))
            ->whereBetween('i.invoice_date', [$from->toDateString(), $to->toDateString()])
            ->get(['l.id as line_id', 'l.account_id', 'l.line_total', 'l.tax_code', 'l.tax_rate', 'l.tax_amount',
                'i.id as doc_id', 'i.number', 'i.party_ref', 'i.direction', 'i.doc_type', 'i.invoice_date', 'i.currency',
                'i.journal_id', 'i.party_id', 'p.name as party_name', 'p.vat_number']);

        $fx = $this->journalRates($invoiceLines->where('currency', '!=', $base)->pluck('journal_id')->unique()->all());

        $out = $invoiceLines->map(function ($l) use ($base, $fx) {
            $direction = $l->direction === 'receivable' ? 'output' : 'input';
            $sign = $l->doc_type === 'credit_note' ? '-1' : '1';
            $rate = $l->currency === $base ? '1' : ($fx[$l->journal_id] ?? null);
            $module = $l->direction === 'receivable' ? 'finance_ar' : 'finance_ap';

            return [
                'source_module' => $module, 'source_table' => 'fin_invoice_lines', 'source_id' => $l->doc_id, 'source_line_id' => $l->line_id,
                'source_key' => "{$module}:fin_invoice_lines:{$l->doc_id}:{$l->line_id}",
                'document_number' => $l->number, 'document_type' => $l->doc_type === 'credit_note' ? 'credit_note' : 'invoice',
                'supplier_ref' => $l->party_ref,
                'document_date' => $l->invoice_date, 'direction' => $direction, 'party_id' => $l->party_id,
                'party_name' => $l->party_name, 'party_vat_number' => $l->vat_number, 'account_id' => $l->account_id,
                'journal_id' => $l->journal_id, 'tax_code' => $l->tax_code ?: null, 'source_rate' => (string) $l->tax_rate,
                'currency' => $l->currency, 'fx_rate' => $rate, 'fx_source' => $l->currency === $base ? null : ($rate ? "journal:{$l->journal_id}" : null),
                'net' => bcmul((string) $l->line_total, $sign, 2), 'tax' => bcmul((string) $l->tax_amount, $sign, 2),
            ];
        });

        $expenses = DB::table('fin_expenses as e')
            ->whereNotNull('e.journal_id')->where('e.tax_amount', '!=', 0)
            ->when($entityId, fn ($q) => $q->where('e.entity_id', $entityId))
            ->whereBetween('e.expense_date', [$from->toDateString(), $to->toDateString()])
            ->get(['e.id', 'e.description', 'e.expense_account_id', 'e.amount', 'e.tax_amount', 'e.expense_date', 'e.journal_id']);

        foreach ($expenses as $e) {
            $out->push([
                'source_module' => 'finance_expense', 'source_table' => 'fin_expenses', 'source_id' => $e->id, 'source_line_id' => null,
                'source_key' => "finance_expense:fin_expenses:{$e->id}",
                'document_number' => 'EXP-' . $e->id, 'document_type' => 'expense', 'supplier_ref' => null,
                'document_date' => $e->expense_date, 'direction' => 'input', 'party_id' => null, 'party_name' => $e->description,
                'party_vat_number' => null, 'account_id' => $e->expense_account_id, 'journal_id' => $e->journal_id,
                'tax_code' => null, 'source_rate' => bccomp((string) $e->amount, '0', 2) === 0 ? '0' : bcmul(bcdiv((string) $e->tax_amount, (string) $e->amount, 6), '100', 3),
                'currency' => $base, 'fx_rate' => '1', 'fx_source' => null,
                'net' => bcadd((string) $e->amount, '0', 2), 'tax' => bcadd((string) $e->tax_amount, '0', 2),
            ]);
        }

        return $out->values();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function upsert(array $line, TaxPeriod $period, TaxType $vat, array &$stats): void
    {
        $hash = $this->hashOf($line);
        $current = TaxTransaction::query()->withoutGlobalScope(EntityScope::class)->current()
            ->where('source_key', $line['source_key'])->orderByDesc('version')->first();

        if ($current && $current->source_hash === $hash) {
            $stats['unchanged']++;

            return;
        }

        $attributes = $this->build($line, $hash, $vat, $period->entity_id);

        if (! $current) {
            TaxTransaction::create($attributes + ['entity_id' => $period->entity_id, 'company_id' => $period->company_id,
                'period_id' => $period->id, 'version' => 1, 'is_current' => true]);
            $stats['created']++;
            app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.transaction.recorded', ['source_key' => $line['source_key'], 'company_id' => $period->company_id]);

            return;
        }

        // The line was found in this period's sources, so its new version belongs to this period —
        // which differs from the old one when the document's date was moved.
        $this->replace($current, $attributes, $period, $stats, $period->id);
    }

    /** Record a change to an existing row: a new version, or a delta in the open period if the old one is final. */
    private function replace(TaxTransaction $current, array $attributes, TaxPeriod $period, array &$stats, int $periodId): void
    {
        $oldPeriod = $current->period;
        $final = $oldPeriod && ! in_array($oldPeriod->status, ['open', 'in_preparation', 'rejected'], true);

        if (! $final) {
            $new = TaxTransaction::create($attributes + ['entity_id' => $current->entity_id, 'company_id' => $current->company_id,
                'period_id' => $periodId, 'version' => $current->version + 1, 'is_current' => true]);
            $current->forceFill(['is_current' => false, 'superseded_by_id' => $new->id])->save();
            $stats['superseded']++;

            return;
        }

        // The return for the old period stands. The change goes where it can still be reported.
        $deltaKey = $current->source_key . ':delta:' . substr($attributes['source_hash'], 0, 12);
        if (TaxTransaction::query()->withoutGlobalScope(EntityScope::class)->where('source_key', $deltaKey)->exists()) {
            $stats['unchanged']++;

            return;
        }
        // Reported in the period being prepared — the one whose refresh found the change.
        $target = $period;
        TaxTransaction::create(array_merge($attributes, [
            'entity_id' => $current->entity_id, 'company_id' => $current->company_id, 'period_id' => $target->id,
            'source_key' => $deltaKey, 'version' => 1, 'is_current' => true, 'document_type' => 'adjustment',
            'taxable_amount' => bcsub((string) $attributes['taxable_amount'], (string) $current->taxable_amount, 2),
            'tax_amount' => bcsub((string) $attributes['tax_amount'], (string) $current->tax_amount, 2),
            'calculated_tax' => $attributes['calculated_tax'] === null ? null : bcsub((string) $attributes['calculated_tax'], (string) ($current->calculated_tax ?? '0'), 2),
            'status' => 'recorded',
            'exceptions' => array_merge($attributes['exceptions'] ?? [], [$this->exception('source_changed_after_finalisation',
                "The source changed after {$oldPeriod->label} was " . str_replace('_', ' ', $oldPeriod->status) . '. This is the difference; accept it to report it here.', true)]),
            'has_exception' => true,
        ]));
        $stats['deltas']++;
    }

    /** Build the register row: code, rule, base-currency amounts, recalculation and exceptions. */
    private function build(array $line, string $hash, TaxType $vat, ?int $entityId): array
    {
        $exceptions = [];
        $code = $line['tax_code'];
        $codeSource = $code ? 'document' : null;

        if (! $code) {
            // Credit notes and many older invoices carry a rate but no code. A positive rate can only
            // be standard-rated; a zero rate could be zero-rated, exempt or out of scope, and the
            // VAT201 field differs, so that one has to be classified by a person.
            if (bccomp($line['source_rate'], '0', 3) > 0) {
                $code = $line['direction'] === 'output' ? 'STD' : 'STDI';
                $codeSource = 'inferred';
                $exceptions[] = $this->exception('inferred_code', "No tax code on the source; {$code} inferred from the {$line['source_rate']}% rate.", false);
            } else {
                $exceptions[] = $this->exception('missing_code', 'No tax code and no VAT: classify as zero-rated, exempt or out of scope.', true);
            }
        }

        $fxRate = $line['fx_rate'];
        if ($fxRate === null) {
            $exceptions[] = $this->exception('no_fx_rate', "Foreign-currency document ({$line['currency']}) with no exchange rate on its journal.", true);
            $fxRate = '1';
        }
        $net = bcmul($line['net'], (string) $fxRate, 2);
        $tax = bcmul($line['tax'], (string) $fxRate, 2);

        $row = [
            'tax_type_id' => $vat->id,
            'source_module' => $line['source_module'], 'source_table' => $line['source_table'], 'source_id' => $line['source_id'],
            'source_line_id' => $line['source_line_id'], 'source_key' => $line['source_key'], 'source_hash' => $hash,
            'superseded_by_id' => null, 'document_number' => $line['document_number'], 'document_type' => $line['document_type'],
            'document_date' => $line['document_date'], 'direction' => $line['direction'], 'party_id' => $line['party_id'],
            'party_name' => $line['party_name'], 'party_vat_number' => $line['party_vat_number'], 'account_id' => $line['account_id'],
            'journal_id' => $line['journal_id'], 'tax_code' => $code, 'tax_code_source' => $codeSource,
            'currency' => $line['currency'], 'fx_rate' => $fxRate, 'fx_source' => $line['fx_source'],
            'taxable_amount' => $net, 'tax_amount' => $tax, 'calculated_tax' => null, 'difference' => '0.00',
            'rule_version_id' => null, 'treatment' => null, 'return_field' => null, 'calculation' => null,
            'status' => 'recorded', 'exceptions' => $exceptions, 'has_exception' => false,
        ];

        $model = new TaxTransaction($row);
        if ($code) {
            $rule = $this->rules->resolve($code, $entityId, $line['document_date']);
            if ($rule) {
                $this->applyRule($model, $rule, $net, $tax);
            } else {
                $model->exceptions = array_merge($model->exceptions ?? [], [$this->exception('no_rule',
                    "No published rule for {$code} on {$line['document_date']}. Publish one under Tax configuration.", true)]);
            }
        }
        $this->recheck($model, $line);
        $model->has_exception = $this->hasBlocking($model->exceptions ?? []);

        // Cast values (arrays, not JSON strings), so create() encodes each JSON column exactly once.
        $out = $model->attributesToArray();
        // A date, not an instant: serialising through a timezone must never move a document a day.
        $out['document_date'] = Carbon::parse($line['document_date'])->toDateString();

        return $out;
    }

    private function applyRule(TaxTransaction $row, $rule, string $net, string $recordedTax): void
    {
        $calc = $this->calculator->calculate($rule, $net, false, ['date' => Carbon::parse($row->document_date)->toDateString(), 'source' => $row->source_key]);
        $row->rule_version_id = $rule->id;
        $row->treatment = $rule->treatment;
        $row->return_field = $rule->return_field;
        $row->calculation = $calc;
        $row->calculated_tax = $calc['tax'];
        $row->difference = $calc['tax'] === null ? '0.00' : bcsub($recordedTax, $calc['tax'], 2);
    }

    /** Checks that depend on the whole row: rate mismatch, direction, valid tax invoice for input VAT. */
    private function recheck(TaxTransaction $row, array $line = []): void
    {
        $exceptions = array_values(array_filter($row->exceptions ?? [], fn ($e) => ! in_array($e['code'], ['rate_mismatch', 'direction_mismatch', 'no_supplier_vat_number'], true) || isset($e['resolved_at'])));

        if ($row->calculated_tax !== null && bccomp(ltrim(bcsub((string) $row->tax_amount, (string) $row->calculated_tax, 2), '-'), self::TAX_TOLERANCE, 2) > 0) {
            $exceptions[] = $this->exception('rate_mismatch', sprintf('VAT on the document is %s; the rule in force (%s at %s%%) gives %s.',
                $row->tax_amount, $row->tax_code, rtrim(rtrim((string) $row->calculation['rate'], '0'), '.'), $row->calculated_tax), true);
        }

        $ruleDirection = $row->calculation ? optional($row->ruleVersion)->direction : null;
        if ($ruleDirection && ! in_array($ruleDirection, ['both', 'none', $row->direction], true)) {
            $exceptions[] = $this->exception('direction_mismatch', "{$row->tax_code} is an {$ruleDirection} code on an {$row->direction} document.", false);
        }

        // Input VAT may only be claimed on a valid tax invoice: the supplier's VAT number is required.
        if ($row->direction === 'input' && $row->source_module === 'finance_ap' && bccomp((string) $row->tax_amount, '0', 2) > 0 && blank($row->party_vat_number)) {
            $exceptions[] = $this->exception('no_supplier_vat_number', "Input VAT claimed from {$row->party_name}, who has no VAT number on file.", true);
        }

        $row->exceptions = $exceptions;
    }

    private function exception(string $code, string $message, bool $blocking): array
    {
        return ['code' => $code, 'message' => $message, 'blocking' => $blocking];
    }

    private function hasBlocking(array $exceptions): bool
    {
        foreach ($exceptions as $e) {
            if (($e['blocking'] ?? false) && empty($e['resolved_at'])) {
                return true;
            }
        }

        return false;
    }

    /** The fields whose change is a change to the tax position. */
    private function hashOf(array $line): string
    {
        return hash('sha256', json_encode([
            $line['document_date'], $line['direction'], $line['document_type'], $line['net'], $line['tax'], $line['tax_code'],
            $line['source_rate'], $line['account_id'], $line['party_id'], $line['currency'], $line['fx_rate'], $line['party_vat_number'],
        ]));
    }

    /** @return array<string, string> journal id → its foreign-currency rate */
    private function journalRates(array $journalIds): array
    {
        if (! $journalIds) {
            return [];
        }

        return DB::table('fin_journal_lines')->whereIn('journal_id', $journalIds)->where('fx_rate', '!=', 1)
            ->select('journal_id', DB::raw('MAX(fx_rate) as rate'))->groupBy('journal_id')->pluck('rate', 'journal_id')
            ->map(fn ($r) => (string) $r)->all();
    }

    private function carried(): array
    {
        return ['tax_type_id', 'source_module', 'source_table', 'source_id', 'source_line_id', 'source_key', 'document_number',
            'document_type', 'document_date', 'direction', 'party_id', 'party_name', 'party_vat_number', 'account_id', 'journal_id',
            'tax_code', 'tax_code_source', 'rule_version_id', 'treatment', 'return_field', 'currency', 'fx_rate', 'fx_source', 'calculation'];
    }

    private function defaultField(string $direction, ?string $treatment): string
    {
        return match (true) {
            $direction === 'input' => '15',
            $treatment === 'zero_rated' => '2',
            $treatment === 'exempt', $treatment === 'out_of_scope' => '3',
            default => '1',
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            '1' => 'Standard-rated supplies',
            '1A' => 'Supplies of capital goods (standard-rated)',
            '2' => 'Zero-rated supplies (excluding exports)',
            '2A' => 'Zero-rated exports',
            '3' => 'Exempt and non-supplies',
            '14' => 'Capital goods acquired (input tax)',
            '15' => 'Other goods and services acquired (input tax)',
            '17' => 'Change in use / other input adjustments',
            default => "Field {$field}",
        };
    }
}
