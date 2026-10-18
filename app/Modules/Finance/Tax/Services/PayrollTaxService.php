<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Models\TaxType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payroll taxes (§7): PAYE, UIF, SDL and ETI taken from approved payroll, not recalculated.
 *
 * Payroll is the authority (§2.2, §7.1). A pay run counts once it is released — the same rule the
 * existing EMP201, EMP501 and IRP5 services apply — and only its finalised payslips. What payroll
 * stores is used as stored: PAYE, employee UIF and ETI per payslip. Two figures payroll does not
 * store are derived exactly as the existing EMP201 derives them, and each derived line says so:
 *
 *   employer UIF  equal to the employee's contribution (1% + 1%)
 *   SDL           gross remuneration × the tax year's SDL rate, and only where the business has a
 *                 confirmed SDL registration (§7.3 "applicability from approved configuration")
 *
 * One register row per payslip per tax, keyed `payroll:payslips:{id}:{TAX}`, so importing twice
 * changes nothing (TAX-007) and a corrected payslip becomes a new version of its row. All rows of a
 * month go to the PAYE registration's period for that month: the EMP201 is one return for all four.
 *
 * Payroll has no business (entity) of its own; its runs belong to the business holding the PAYE
 * registration the import is run for.
 */
class PayrollTaxService
{
    private const TYPES = ['PAYE', 'UIF', 'SDL', 'ETI'];

    public function __construct(private readonly TaxPeriodService $periods, private readonly TaxAudit $audit)
    {
    }

    /** @return array{created:int, superseded:int, unchanged:int, excluded:int, runs:int, payslips:int, exceptions:int} */
    public function import(TaxPeriod $period): array
    {
        if ($period->taxType?->code !== 'PAYE') {
            throw new TaxControlException('Payroll is imported into a PAYE (EMP201) period.');
        }
        $this->periods->assertProcessable($period);

        $types = TaxType::whereIn('code', self::TYPES)->get()->keyBy('code');
        $stats = ['created' => 0, 'superseded' => 0, 'unchanged' => 0, 'excluded' => 0, 'runs' => 0, 'payslips' => 0, 'exceptions' => 0];

        DB::transaction(function () use ($period, $types, &$stats) {
            $slips = $this->payslips($period);
            $stats['payslips'] = $slips->count();
            $stats['runs'] = $slips->pluck('run_id')->unique()->count();
            $sdlLiable = $this->sdlRegistered($period);
            $perEmployee = $slips->countBy('employee_id');
            $seen = [];

            foreach ($slips as $s) {
                $taxYear = DB::table('sa_tax_years')->whereDate('starts_on', '<=', $s->period_end)->whereDate('ends_on', '>=', $s->period_end)
                    ->first(['tax_year', 'sdl_rate', 'uif_rate', 'uif_monthly_ceiling']);
                $common = $this->exceptions($s, $taxYear, $perEmployee[$s->employee_id] ?? 1);

                $lines = [
                    'PAYE' => [(string) $s->paye, ['source' => 'payslip.paye']],
                    'UIF' => [bcmul((string) $s->uif, '2', 2), ['source' => 'payslip.uif', 'employee' => (string) $s->uif, 'employer' => (string) $s->uif,
                        'note' => 'Employer contribution equal to the employee\'s, as payroll\'s EMP201 declares it.']],
                    'ETI' => [(string) $s->eti, ['source' => 'payslip.eti']],
                ];
                if ($sdlLiable) {
                    $rate = (string) ($taxYear->sdl_rate ?? '0.01');
                    $lines['SDL'] = [$this->roundHalfUp(bcmul((string) $s->gross_pay, $rate, 6)), ['source' => 'derived', 'basis' => 'gross × SDL rate',
                        'rate' => $rate, 'tax_year' => $taxYear->tax_year ?? null]];
                }

                foreach ($lines as $code => [$amount, $calc]) {
                    if ($code === 'ETI' && bccomp($amount, '0', 2) === 0) {
                        continue;
                    }
                    $key = "payroll:payslips:{$s->payslip_id}:{$code}";
                    $seen[] = $key;
                    $exceptions = $code === 'UIF' || $code === 'PAYE' ? $common : [];
                    $row = [
                        'tax_type_id' => $types[$code]->id, 'source_module' => 'payroll', 'source_table' => 'payslips',
                        'source_id' => $s->payslip_id, 'source_line_id' => null, 'source_key' => $key,
                        'document_number' => $s->run_ref, 'document_type' => 'payslip', 'document_date' => Carbon::parse($s->period_end)->toDateString(),
                        'direction' => $code === 'ETI' ? 'offset' : 'liability', 'party_id' => $s->employee_id, 'party_name' => $s->employee_name,
                        'currency' => 'ZAR', 'fx_rate' => '1', 'taxable_amount' => bcadd((string) $s->gross_pay, '0', 2), 'tax_amount' => bcadd($amount, '0', 2),
                        'calculated_tax' => null, 'difference' => '0.00', 'tax_code' => $code, 'tax_code_source' => 'document',
                        'calculation' => $calc + ['payroll_run_id' => $s->run_id, 'payroll_run_status' => 'released', 'tax_year' => $taxYear->tax_year ?? null],
                        'exceptions' => $exceptions, 'has_exception' => collect($exceptions)->contains(fn ($e) => $e['blocking']),
                        'status' => 'recorded', 'journal_id' => $s->journal_id,
                    ];
                    $row['source_hash'] = hash('sha256', json_encode([$amount, $s->gross_pay, $s->period_end, $s->payslip_status, $s->employee_id]));
                    $this->upsert($row, $period, $stats);
                }
            }

            // A payslip that is no longer released and finalised (run cancelled, payslip reopened).
            $gone = TaxTransaction::query()->current()->where('period_id', $period->id)->where('source_module', 'payroll')
                ->where('status', '!=', 'excluded')->whereNotIn('source_key', $seen ?: ['-'])->get();
            foreach ($gone as $row) {
                $new = $row->replicate(['uuid']);
                $new->fill(['version' => $row->version + 1, 'status' => 'excluded', 'tax_amount' => '0.00', 'taxable_amount' => '0.00',
                    'source_hash' => hash('sha256', 'excluded:' . $row->source_key), 'exceptions' => [], 'has_exception' => false]);
                $new->save();
                $row->forceFill(['is_current' => false, 'superseded_by_id' => $new->id])->save();
                $stats['excluded']++;
            }

            $this->audit->record('payroll_tax.imported', $period, ['period_id' => $period->id, 'tax_type' => 'PAYE', 'after' => $stats]);
        });

        $stats['exceptions'] = TaxTransaction::query()->current()->where('period_id', $period->id)->where('source_module', 'payroll')
            ->where('has_exception', true)->count();

        if ($period->status === 'open') {
            $this->periods->transition($period, 'in_preparation', 'Payroll imported.');
        }

        return $stats;
    }

    /**
     * Employer-level and per-run totals for a period (§7.1 employer-level summary). Employee-level
     * figures are a separate call, gated by tax.payroll.view where it is used.
     *
     * @return array{by_type: array<string,string>, runs: list<array<string,mixed>>, employees:int, total_payable:string}
     */
    public function summary(TaxPeriod $period): array
    {
        $rows = $this->rows($period);
        $byType = [];
        foreach (self::TYPES as $t) {
            $byType[$t] = array_reduce($rows->filter(fn ($r) => $r->taxType->code === $t)->all(), fn ($c, $r) => bcadd($c, (string) $r->tax_amount, 2), '0.00');
        }
        $runs = $rows->groupBy(fn ($r) => $r->calculation['payroll_run_id'] ?? 0)->map(function ($g, $runId) {
            $sum = fn ($code) => array_reduce($g->filter(fn ($r) => $r->taxType->code === $code)->all(), fn ($c, $r) => bcadd($c, (string) $r->tax_amount, 2), '0.00');

            return ['run_id' => $runId, 'reference' => $g->first()->document_number, 'employees' => $g->pluck('party_id')->unique()->count(),
                'paye' => $sum('PAYE'), 'uif' => $sum('UIF'), 'sdl' => $sum('SDL'), 'eti' => $sum('ETI')];
        })->values()->all();

        return [
            'by_type' => $byType, 'runs' => $runs, 'employees' => $rows->pluck('party_id')->unique()->count(),
            'total_payable' => bcsub(bcadd(bcadd($byType['PAYE'], $byType['UIF'], 2), $byType['SDL'], 2), $byType['ETI'], 2),
        ];
    }

    /** @return list<array<string,mixed>> employee-level detail — callers must check tax.payroll.view (§16). */
    public function employees(TaxPeriod $period): array
    {
        return $this->rows($period)->groupBy('party_id')->map(function ($g) {
            $get = fn ($code) => (string) ($g->first(fn ($r) => $r->taxType->code === $code)?->tax_amount ?? '0.00');

            return ['employee_id' => $g->first()->party_id, 'name' => $g->first()->party_name,
                'gross' => (string) $g->first()->taxable_amount, 'paye' => $get('PAYE'), 'uif' => $get('UIF'), 'sdl' => $get('SDL'), 'eti' => $get('ETI'),
                'exceptions' => $g->flatMap(fn ($r) => collect($r->exceptions ?? [])->pluck('message'))->unique()->values()->all()];
        })->sortBy('name')->values()->all();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** Released runs whose pay period ends in this tax period, finalised payslips only. */
    private function payslips(TaxPeriod $period): Collection
    {
        return DB::table('payslips as p')
            ->join('payroll_runs as r', 'r.id', '=', 'p.payroll_run_id')
            ->leftJoin('hr_people as h', 'h.user_id', '=', 'p.employee_id')
            ->leftJoin('fin_journals as j', fn ($j) => $j->where('j.source_module', 'payroll')->whereRaw("j.source_ref = 'payroll_run:' || r.id"))
            ->where('r.company_id', $period->company_id)
            ->where('r.status', 'released')->whereNull('r.deleted_at')
            ->where('p.status', '!=', 'draft')
            ->whereBetween('r.period_end', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->orderBy('r.id')->orderBy('p.id')
            ->get(['p.id as payslip_id', 'p.employee_id', 'p.status as payslip_status', 'p.gross_pay', 'p.paye', 'p.uif', 'p.eti',
                'r.id as run_id', 'r.ref_number as run_ref', 'r.period_end', 'j.id as journal_id',
                DB::raw("TRIM(COALESCE(h.first_name,'') || ' ' || COALESCE(h.surname,'')) as employee_name")]);
    }

    private function rows(TaxPeriod $period): Collection
    {
        return TaxTransaction::query()->current()->where('period_id', $period->id)->where('source_module', 'payroll')
            ->where('status', '!=', 'excluded')->with('taxType:id,code')->get();
    }

    private function sdlRegistered(TaxPeriod $period): bool
    {
        return TaxRegistration::query()->where('entity_id', $period->entity_id)->where('status', 'active')->where('applicability_confirmed', true)
            ->whereHas('taxType', fn ($q) => $q->where('code', 'SDL'))
            ->whereDate('effective_from', '<=', $period->end_date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->start_date))->exists();
    }

    /** §7.2: missing, duplicate or inconsistent employee-level amounts. */
    private function exceptions(object $s, ?object $taxYear, int $slipsThisMonth): array
    {
        $out = [];
        if ($slipsThisMonth > 1) {
            $out[] = ['code' => 'duplicate_employee', 'message' => "{$s->employee_name} has {$slipsThisMonth} released payslips in this period.", 'blocking' => false];
        }
        if (bccomp((string) $s->paye, '0', 2) < 0 || bccomp((string) $s->uif, '0', 2) < 0) {
            $out[] = ['code' => 'negative_amount', 'message' => 'Negative PAYE or UIF on the payslip.', 'blocking' => true];
        }
        if ($taxYear && $taxYear->uif_rate) {
            $base = (string) $s->gross_pay;
            if ($taxYear->uif_monthly_ceiling && bccomp($base, (string) $taxYear->uif_monthly_ceiling, 2) > 0) {
                $base = (string) $taxYear->uif_monthly_ceiling;
            }
            $expected = $this->roundHalfUp(bcmul($base, (string) $taxYear->uif_rate, 6));
            if (bccomp(ltrim(bcsub((string) $s->uif, $expected, 2), '-'), '1.00', 2) > 0) {
                $out[] = ['code' => 'uif_inconsistent', 'message' => "Employee UIF {$s->uif} differs from {$expected} at the tax year's rate and ceiling.", 'blocking' => false];
            }
        }
        if (blank(trim((string) $s->employee_name))) {
            $out[] = ['code' => 'unknown_employee', 'message' => 'The payslip\'s employee has no HR record.', 'blocking' => false];
        }

        return $out;
    }

    private function upsert(array $row, TaxPeriod $period, array &$stats): void
    {
        $current = TaxTransaction::query()->current()->where('source_key', $row['source_key'])->orderByDesc('version')->first();
        if ($current && $current->source_hash === $row['source_hash']) {
            $stats['unchanged']++;

            return;
        }
        $new = TaxTransaction::create($row + ['entity_id' => $period->entity_id, 'company_id' => $period->company_id, 'period_id' => $period->id,
            'version' => ($current?->version ?? 0) + 1, 'is_current' => true]);
        if ($current) {
            $current->forceFill(['is_current' => false, 'superseded_by_id' => $new->id])->save();
            $stats['superseded']++;
        } else {
            $stats['created']++;
        }
    }

    private function roundHalfUp(string $value): string
    {
        return app(TaxCalculator::class)->round($value, 2, 'half_up');
    }
}
