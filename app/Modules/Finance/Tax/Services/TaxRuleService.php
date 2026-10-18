<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Exceptions\TaxRuleException;
use App\Modules\Finance\Tax\Models\TaxRuleVersion;
use App\Modules\Finance\Tax\Models\TaxType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated, versioned tax rules (§5.2).
 *
 *   draft → (simulate) → pending_approval → published → retired
 *                              ↘ rejected (back to an editable draft)
 *
 * TAX-RULE-002  every version has its own number and effective dates.
 * TAX-RULE-003  a published version cannot be edited (the model refuses); change = a new version.
 * TAX-RULE-004  future-dated versions; retirement closes effective_to, it never deletes.
 * TAX-RULE-005  a draft must be simulated, unchanged since, before it can be submitted.
 * TAX-RULE-006  approval by someone other than the author and the submitter.
 * TAX-RULE-007  publishing refuses an overlap with another published version of the same code and
 *               entity scope. `closePrevious` resolves the common case — a rate change from a date —
 *               by ending the version in force the day before, as an audited part of publishing.
 */
class TaxRuleService
{
    public function __construct(private readonly TaxAudit $audit, private readonly TaxCalculator $calculator)
    {
    }

    /** The published version in force for a code on a date: an entity's own rule before the tenant's. */
    public function resolve(string $taxCode, ?int $entityId, Carbon|string $date): ?TaxRuleVersion
    {
        $day = Carbon::parse($date)->toDateString();

        return TaxRuleVersion::query()->with('taxType')
            ->where('tax_code', $taxCode)->where('status', 'published')
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByRaw('entity_id IS NULL')        // entity-specific first
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @param array<string, mixed> $data */
    public function draft(array $data): TaxRuleVersion
    {
        return DB::transaction(function () use ($data) {
            $type = TaxType::findOrFail($data['tax_type_id']);
            $entityId = $data['entity_id'] ?? null;

            $previous = TaxRuleVersion::query()
                ->where('tax_code', $data['tax_code'])
                ->where(fn ($q) => $entityId ? $q->where('entity_id', $entityId) : $q->whereNull('entity_id'))
                ->orderByDesc('version')->first();

            $rule = TaxRuleVersion::create($this->fields($data) + [
                'tax_type_id' => $type->id,
                'tax_code' => strtoupper(trim($data['tax_code'])),
                'entity_id' => $entityId,
                'version' => ($previous?->version ?? 0) + 1,
                'supersedes_id' => $previous?->status === 'published' ? $previous->id : null,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            $this->audit->record('rule.drafted', $rule, ['tax_type' => $type->code, 'rule_version_id' => $rule->id,
                'after' => $rule->only(['tax_code', 'version', 'rate', 'treatment', 'effective_from', 'effective_to'])]);

            return $rule;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(TaxRuleVersion $rule, array $data): TaxRuleVersion
    {
        $this->expect($rule, ['draft', 'rejected'], 'Only a draft can be edited.');

        return DB::transaction(function () use ($rule, $data) {
            $before = $rule->only(array_keys($this->fields($data)));
            // Any edit invalidates the last simulation: it tested something else.
            $rule->fill($this->fields($data) + ['status' => 'draft', 'simulation' => null])->save();
            $this->audit->record('rule.edited', $rule, ['rule_version_id' => $rule->id, 'before' => $before, 'after' => $rule->only(array_keys($before))]);

            return $rule;
        });
    }

    /**
     * TAX-RULE-005: run the draft against test cases without publishing it.
     *
     * The draft is evaluated as if published, in memory only. The result is stored with a
     * fingerprint of the rule's fields, so submission can tell whether what was tested is still
     * what would be published.
     *
     * @param  list<array{amount:string, inclusive?:bool, expected_tax?:string|null}>  $cases
     */
    public function simulate(TaxRuleVersion $rule, array $cases): array
    {
        $this->expect($rule, ['draft', 'rejected'], 'Only a draft is simulated; a published rule is already in use.');

        $probe = clone $rule;
        $probe->status = 'published';
        $results = [];
        $passed = true;

        foreach ($cases as $case) {
            $out = $this->calculator->calculate($probe, (string) $case['amount'], (bool) ($case['inclusive'] ?? false));
            $ok = ! isset($case['expected_tax']) || $case['expected_tax'] === null || $case['expected_tax'] === ''
                || ($out['tax'] !== null && bccomp($out['tax'], (string) $case['expected_tax'], 2) === 0);
            $passed = $passed && $ok && $out['status'] === 'calculated';
            $results[] = ['amount' => (string) $case['amount'], 'inclusive' => (bool) ($case['inclusive'] ?? false),
                'expected_tax' => $case['expected_tax'] ?? null, 'tax' => $out['tax'], 'base' => $out['taxable_base'],
                'status' => $out['status'], 'passed' => $ok];
        }

        $simulation = ['ran_at' => now()->toIso8601String(), 'by' => Auth::id(), 'passed' => $passed,
            'fingerprint' => $this->fingerprint($rule), 'cases' => $results];

        // saveQuietly: a simulation is a test result, not an edit, and must not clear itself.
        $rule->forceFill(['simulation' => $simulation])->saveQuietly();
        $this->audit->record('rule.simulated', $rule, ['rule_version_id' => $rule->id, 'after' => ['passed' => $passed, 'cases' => count($results)]]);

        return $simulation;
    }

    public function submit(TaxRuleVersion $rule, ?string $reason = null): TaxRuleVersion
    {
        $this->expect($rule, ['draft', 'rejected'], 'Only a draft can be submitted for approval.');

        $sim = $rule->simulation;
        if (! $sim || ! ($sim['passed'] ?? false)) {
            throw new TaxRuleException('Run the simulation, with every test case passing, before submitting the rule.');
        }
        if (($sim['fingerprint'] ?? null) !== $this->fingerprint($rule)) {
            throw new TaxRuleException('The rule changed after it was simulated. Simulate it again.');
        }
        // Surfaced at submission so the approver is not the first to find out.
        if ($conflict = $this->conflictFor($rule, ignoreClosable: true)) {
            throw new TaxRuleException(sprintf(
                'Version %d of %s (from %s) overlaps these dates and cannot be ended to make room. Change the effective dates first.',
                $conflict->version, $conflict->tax_code, $conflict->effective_from->toDateString()
            ));
        }

        return DB::transaction(function () use ($rule, $reason) {
            $rule->forceFill(['status' => 'pending_approval', 'submitted_by' => Auth::id(), 'submitted_at' => now(),
                'change_reason' => $reason ?? $rule->change_reason])->saveQuietly();
            $this->audit->record('rule.submitted', $rule, ['rule_version_id' => $rule->id, 'reason' => $reason]);

            return $rule;
        });
    }

    public function reject(TaxRuleVersion $rule, string $comment): TaxRuleVersion
    {
        $this->expect($rule, ['pending_approval'], 'Only a rule awaiting approval can be rejected.');

        return DB::transaction(function () use ($rule, $comment) {
            $rule->forceFill(['status' => 'rejected', 'approval_comment' => $comment])->saveQuietly();
            $this->audit->record('rule.rejected', $rule, ['rule_version_id' => $rule->id, 'reason' => $comment]);

            return $rule;
        });
    }

    /**
     * Approve and publish in one step: an approved rule that is not in force helps nobody, and a
     * gap between the two is a window in which the approver's decision and the system disagree.
     */
    public function approveAndPublish(TaxRuleVersion $rule, ?string $comment = null, bool $closePrevious = false): TaxRuleVersion
    {
        $this->expect($rule, ['pending_approval'], 'Only a rule awaiting approval can be approved.');

        $me = Auth::id();
        if ($me !== null && in_array($me, array_filter([$rule->created_by, $rule->submitted_by]), true)) {
            $this->audit->denied('rule.approve', $rule, 'Self-approval refused (segregation of duties).', ['rule_version_id' => $rule->id]);
            throw new TaxRuleException('You drafted or submitted this rule, so someone else has to approve it.');
        }

        return DB::transaction(function () use ($rule, $comment, $closePrevious) {
            // Lock the code's published versions so two approvals cannot both pass the overlap check.
            TaxRuleVersion::query()->where('tax_code', $rule->tax_code)->where('status', 'published')->lockForUpdate()->get();

            if ($conflict = $this->conflictFor($rule, ignoreClosable: $closePrevious)) {
                throw new TaxRuleException(sprintf(
                    'Version %d of %s is in force from %s%s and overlaps this one. End it first, or publish with "end the previous version".',
                    $conflict->version, $conflict->tax_code, $conflict->effective_from->toDateString(),
                    $conflict->effective_to ? ' to ' . $conflict->effective_to->toDateString() : ''
                ));
            }

            if ($closePrevious) {
                foreach ($this->overlapping($rule) as $old) {
                    $end = $rule->effective_from->copy()->subDay();
                    $before = ['effective_to' => $old->effective_to?->toDateString()];
                    $old->effective_to = $end;
                    $old->save();
                    $this->audit->record('rule.end_dated', $old, ['rule_version_id' => $old->id, 'before' => $before,
                        'after' => ['effective_to' => $end->toDateString()], 'reason' => "Superseded by version {$rule->version}"]);
                }
            }

            $rule->forceFill(['status' => 'published', 'approved_by' => Auth::id(), 'approved_at' => now(),
                'approval_comment' => $comment, 'published_at' => now()])->saveQuietly();
            $this->audit->record('rule.published', $rule, ['rule_version_id' => $rule->id, 'tax_type' => $rule->taxType?->code,
                'reason' => $comment, 'after' => $rule->only(['tax_code', 'version', 'rate', 'treatment', 'effective_from', 'effective_to'])]);

            app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.rule_version_published', ['rule_version_id' => $rule->id, 'company_id' => $rule->company_id]);

            return $rule;
        });
    }

    /** TAX-RULE-004: retire from a date. History stays; calculations before the date are unaffected. */
    public function retire(TaxRuleVersion $rule, Carbon|string $from, string $reason): TaxRuleVersion
    {
        $this->expect($rule, ['published'], 'Only a published rule is retired.');
        $end = Carbon::parse($from)->subDay();
        if ($end->lt($rule->effective_from)) {
            throw new TaxRuleException('A rule cannot be retired before it took effect. Reject or replace it instead.');
        }

        return DB::transaction(function () use ($rule, $end, $reason) {
            $before = ['effective_to' => $rule->effective_to?->toDateString(), 'status' => $rule->status];
            $rule->effective_to = $end;
            $rule->status = $end->lt(today()) ? 'retired' : 'published';
            $rule->retired_at = now();
            $rule->save();
            $this->audit->record('rule.retired', $rule, ['rule_version_id' => $rule->id, 'reason' => $reason, 'before' => $before,
                'after' => ['effective_to' => $end->toDateString(), 'status' => $rule->status]]);

            return $rule;
        });
    }

    /**
     * Drafts from Finance's posting codes, so a tenant starts from what its invoices already use
     * rather than retyping it. Drafts only: nothing is in force until a person approves it (TAX-REG-003).
     */
    public function draftsFromFinanceCodes(?int $entityId = null): int
    {
        $vat = TaxType::byCode('VAT');
        $created = 0;

        foreach (DB::table('fin_tax_codes')->where('is_active', true)->get() as $code) {
            $exists = TaxRuleVersion::query()->where('tax_code', $code->code)
                ->where(fn ($q) => $entityId ? $q->where('entity_id', $entityId) : $q->whereNull('entity_id'))->exists();
            if ($exists || $code->type === 'wht') {
                continue;
            }

            $treatment = match (true) {
                $code->type === 'none' => 'exempt',
                (float) $code->rate === 0.0 => 'zero_rated',
                default => 'standard',
            };

            $this->draft([
                'tax_type_id' => $vat->id, 'tax_code' => $code->code, 'entity_id' => $entityId,
                'name' => $code->name, 'treatment' => $treatment, 'direction' => $code->type === 'none' ? 'both' : $code->type,
                'method' => 'percentage', 'rate' => (string) $code->rate, 'rounding' => 'half_up', 'precision' => 2,
                'control_account_id' => $code->account_id, 'return_field' => null,
                'effective_from' => '2018-04-01', 'legal_reference' => null,
                'change_reason' => 'Drafted from Finance tax code ' . $code->code . '. Confirm the treatment, return field and dates before submitting.',
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Drafts of the company income tax rate (code CIT): 28% for years of assessment ending before
     * 31 March 2023, 27% from then. The rule is resolved at the year-end date, which is how the rate
     * change applies. Drafts only — simulated and approved like any other rule before it is used.
     */
    public function draftIncomeTaxRules(): int
    {
        if (TaxRuleVersion::query()->where('tax_code', 'CIT')->exists()) {
            return 0;
        }
        $cit = TaxType::byCode('CIT');
        foreach ([['28', '2012-04-01', '2023-03-30', 'Companies, years ending before 31 March 2023'],
            ['27', '2023-03-31', null, 'Companies, years of assessment ending on or after 31 March 2023']] as [$rate, $from, $to, $why]) {
            $this->draft(['tax_type_id' => $cit->id, 'tax_code' => 'CIT', 'name' => "Company income tax {$rate}%", 'treatment' => 'standard',
                'direction' => 'none', 'method' => 'percentage', 'rate' => $rate, 'rounding' => 'half_up', 'precision' => 2,
                'effective_from' => $from, 'effective_to' => $to, 'legal_reference' => 'Income Tax Act, Schedule 1 rates (company)',
                'change_reason' => $why . '. Confirm before submitting.']);
        }

        return 2;
    }

    /** Published versions of the same code and entity scope whose dates overlap this one's. */
    public function overlapping(TaxRuleVersion $rule): Collection
    {
        $from = $rule->effective_from->toDateString();
        $to = $rule->effective_to?->toDateString();

        return TaxRuleVersion::query()
            ->where('tax_code', $rule->tax_code)->where('status', 'published')->whereKeyNot($rule->id)
            ->where(fn ($q) => $rule->entity_id ? $q->where('entity_id', $rule->entity_id) : $q->whereNull('entity_id'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('effective_from', '<=', $to))
            ->get();
    }

    /**
     * The first overlap that blocks publication. With $ignoreClosable, an older open-ended or
     * longer version that starts before this one is not a conflict: ending it the day before is
     * exactly what "close previous" does. Anything that starts on or after this one still is.
     */
    private function conflictFor(TaxRuleVersion $rule, bool $ignoreClosable): ?TaxRuleVersion
    {
        return $this->overlapping($rule)->first(function (TaxRuleVersion $old) use ($rule, $ignoreClosable) {
            return ! ($ignoreClosable && $old->effective_from->lt($rule->effective_from));
        });
    }

    private function fingerprint(TaxRuleVersion $rule): string
    {
        return hash('sha256', json_encode([
            // Normalised: "15" as typed and "15.000000" as stored are the same rate.
            $rule->tax_code, $rule->treatment, $rule->direction, $rule->method, bcadd((string) $rule->rate, '0', 6), $rule->rounding,
            (int) $rule->precision, $rule->effective_from?->toDateString(), $rule->effective_to?->toDateString(),
            $rule->entity_id === null ? null : (int) $rule->entity_id,
        ]));
    }

    /** @param array<string, mixed> $data */
    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'treatment', 'direction', 'method', 'rate', 'rounding', 'precision', 'control_account_id',
            'return_field', 'reporting_class', 'effective_from', 'effective_to', 'legal_reference', 'change_reason',
        ]));
    }

    private function expect(TaxRuleVersion $rule, array $statuses, string $message): void
    {
        if (! in_array($rule->status, $statuses, true)) {
            throw new TaxRuleException($message);
        }
    }
}
