<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Models\SarsTaxConfig;
use App\Modules\Finance\Sars\Services\SarsValidator;
use App\Modules\Tenancy\Services\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a monthly UI-19 UIF declaration — the per-employee list the employer submits to the
 * UIF (Department of Employment & Labour): each employee's UIF remuneration and 1%+1%
 * contribution for the month, plus engagements (date_joined in the month) and terminations
 * (date_left in the month). Sourced from finalised normal-engine payroll, tenant-scoped by
 * company_id. On generate() it persists a versioned SarsSubmission with a stored PDF into the
 * shared submission history. Read paths never write; generate() is the only mutation.
 */
class Ui19Service
{
    // UIF status-change reason codes (UI-19).
    private const REASON_NEW = '01';         // appointment / new engagement
    private const REASON_ACTIVE = '';        // continued employment — no status change
    private const REASON_TERMINATED = '17';  // termination (generic — resignation/dismissal not distinguished here)

    public function __construct(
        private SarsValidator $validator,
        private CurrentCompany $currentCompany,
    ) {
    }

    /**
     * The tenant to scope aggregation to. Always a concrete id so a null current company can
     * never aggregate across tenants — falls back to the user's own company, then a sentinel.
     */
    private function companyId(): int
    {
        return (int) ($this->currentCompany->id() ?? auth()->user()?->company_id ?? -1);
    }

    public function defaultMonth(): string
    {
        return Carbon::today()->startOfMonth()->subMonth()->format('Y-m');
    }

    /** Assemble the UI-19 for a tax month WITHOUT persisting. */
    public function build(string $month): array
    {
        $config = SarsTaxConfig::current();
        [$from, $to] = $this->monthBounds($month);
        $employees = $this->employees($from, $to);

        $totalRemuneration = round(array_sum(array_column($employees, 'remuneration')), 2);
        $uifTotal = round(array_sum(array_column($employees, 'uif_total')), 2);
        $newCount = count(array_filter($employees, fn ($e) => $e['status'] === 'new'));
        $termCount = count(array_filter($employees, fn ($e) => $e['status'] === 'terminated'));

        return [
            'period' => ['month' => $month, 'from' => $from, 'to' => $to, 'label' => Carbon::parse($from)->format('M Y')],
            'header' => [
                'company_name'   => (string) (DB::table('companies')->where('id', $this->currentCompany->id())->value('name') ?? ''),
                'uif_reference'  => $config->uif_reference,
                'paye_reference' => $config->paye_reference,
                'contact_person' => $config->contact_person,
                'contact_email'  => $config->contact_email,
            ],
            'employees' => $employees,
            'totals'    => [
                'employee_count'    => count($employees),
                'new_count'         => $newCount,
                'terminated_count'  => $termCount,
                'total_remuneration' => $totalRemuneration,
                'uif_employee'      => round($uifTotal / 2, 2),
                'uif_employer'      => round($uifTotal / 2, 2),
                'uif_total'         => $uifTotal,
            ],
            'validation' => $this->validate($config, $employees),
        ];
    }

    /** One row per employee paid in the month, with UIF figures and status change. */
    private function employees(string $from, string $to): array
    {
        $rows = DB::table('payslips as p')
            ->join('payroll_runs as r', 'r.id', '=', 'p.payroll_run_id')
            ->leftJoin('hr_people as h', 'h.id', '=', 'p.employee_id')
            ->where('r.status', 'released')
            ->where('p.status', '!=', 'draft')
            ->whereBetween('r.period_end', [$from, $to])
            ->where('p.company_id', $this->companyId())
            ->groupBy('p.employee_id', 'h.first_name', 'h.surname', 'h.id_number', 'h.date_joined', 'h.date_left')
            ->selectRaw('p.employee_id, h.first_name, h.surname, h.id_number, h.date_joined, h.date_left,
                COALESCE(SUM(p.gross_pay),0) remuneration, COALESCE(SUM(p.uif),0) uif')
            ->orderBy('h.surname')->get();

        return $rows->map(function ($r) use ($from, $to) {
            $uifEmployee = round((float) $r->uif, 2);
            $joined = $r->date_joined ? Carbon::parse($r->date_joined) : null;
            $left = $r->date_left ? Carbon::parse($r->date_left) : null;

            $status = 'active';
            $reason = self::REASON_ACTIVE;
            if ($left && $left->between($from, $to)) {
                $status = 'terminated';
                $reason = self::REASON_TERMINATED;
            } elseif ($joined && $joined->between($from, $to)) {
                $status = 'new';
                $reason = self::REASON_NEW;
            }

            return [
                'employee_id'   => (int) $r->employee_id,
                'name'          => trim(($r->first_name ?? '') . ' ' . ($r->surname ?? '')) ?: ('Employee #' . $r->employee_id),
                'id_number'     => $r->id_number,
                'date_joined'   => $joined?->toDateString(),
                'date_left'     => $left?->toDateString(),
                'remuneration'  => round((float) $r->remuneration, 2),
                'uif_employee'  => $uifEmployee,
                'uif_employer'  => $uifEmployee,
                'uif_total'     => round($uifEmployee * 2, 2),
                'status'        => $status,
                'reason_code'   => $reason,
            ];
        })->all();
    }

    private function validate(SarsTaxConfig $config, array $employees): array
    {
        $result = $this->validator->validateConfig($config);
        $errors = $result['errors'];

        if (blank($config->uif_reference)) {
            $errors[] = ['entity' => 'config', 'id' => null, 'field' => 'uif_reference', 'message' => 'A UIF reference number is required to file UI-19.'];
        }
        if (count($employees) === 0) {
            $errors[] = ['entity' => 'ui19', 'id' => null, 'field' => 'period', 'message' => 'No finalised payroll in this month — there are no employees to declare.'];
        }
        foreach ($employees as $e) {
            if (blank($e['id_number'])) {
                $errors[] = ['entity' => 'employee', 'id' => $e['employee_id'], 'field' => 'id_number', 'message' => $e['name'] . ' has no ID number on their profile (required for UIF).'];
            }
        }

        return ['validated' => 2 + count($employees), 'errors' => array_values($errors)];
    }

    /** Generate and persist the UI-19 as a versioned submission with a stored PDF. */
    public function generate(string $month, ?string $notes = null): SarsSubmission
    {
        $data = $this->build($month);
        $companyId = $this->currentCompany->id();

        $prior = SarsSubmission::ownReturns()->where('tax_type', 'ui19')->where('period', $month)->orderByDesc('version')->first();
        $hasErrors = ! empty($data['validation']['errors']);

        $submission = SarsSubmission::create([
            'company_id'   => $companyId,
            'tax_type'     => 'ui19',
            'period'       => $month,
            'status'       => $hasErrors ? 'generated' : 'validated',
            'version'      => $prior ? $prior->version + 1 : 1,
            'amends_id'    => $prior?->id,
            'totals'       => $data['totals'],
            'payload'      => $data,
            'validation'   => $data['validation'],
            'notes'        => $notes,
            'generated_by' => Auth::id(),
            'generated_at' => now(),
        ]);

        $pdf = Pdf::loadView('pdf.sars.ui19', ['data' => $data, 'submission' => $submission])->setPaper('a4', 'landscape');
        $path = "sars/{$companyId}/ui19/{$submission->reference}.pdf";
        Storage::put($path, $pdf->output());
        $submission->update(['file_path' => $path]);

        // Same opening trail entry as the EMP201: every return in this history answers "who
        // generated this, and when" the same way, whichever screen produced it.
        app(\App\Modules\Finance\Sars\Services\SubmissionStatusService::class)->recordGenerated($submission);

        return $submission;
    }

    /** @return array{0:string,1:string} [firstDay, lastDay] of the YYYY-MM month. */
    private function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return [$start->toDateString(), (clone $start)->endOfMonth()->toDateString()];
    }
}
