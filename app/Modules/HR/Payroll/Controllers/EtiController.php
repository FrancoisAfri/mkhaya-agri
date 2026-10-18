<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Employment Tax Incentive register: who is claiming, how many of the twenty-four months each
 * person has used, and what remains.
 *
 * The ledger behind this has been written on every run since it was built, and nothing has ever
 * shown it. summaryFor() and historyFor() existed with no caller — so the one question an employer
 * actually has about ETI ("how much of this is left, and when does it stop?") could only be
 * answered with a SQL client, and the answer arrives as a surprise in the month the incentive ends
 * and the PAYE bill jumps.
 */
class EtiController extends Controller
{
    public function __construct(private EtiClaimService $claims)
    {
    }

    public function index(Request $request): Response
    {
        $filter = (string) $request->input('filter', 'claiming');

        /*
         * The register is built from the ledger, not from the staff list.
         *
         * Somebody who has never claimed has nothing to show — and listing every employee would
         * bury the twenty or thirty people this actually concerns under everybody else. A former
         * employee who claimed in the past still belongs here: their used months are part of the
         * employer's history, and the question "did we claim for this person" outlives them.
         */
        $rows = DB::table('eti_claims as c')
            ->leftJoin('hr_people as p', function ($join) {
                $join->on('p.user_id', '=', 'c.employee_id')
                    ->where('c.employee_type', '=', EtiClaim::HR);
            })
            ->where('c.employee_type', EtiClaim::HR)
            ->where('c.company_id', $this->companyId())
            ->groupBy('c.employee_id', 'p.first_name', 'p.surname', 'p.employee_number')
            ->selectRaw("
                c.employee_id,
                TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.surname, ''))) as name,
                p.employee_number,
                COUNT(*) FILTER (WHERE c.eligible) as claimed,
                COALESCE(SUM(c.amount) FILTER (WHERE c.eligible), 0) as total,
                MAX(c.period_key) FILTER (WHERE c.eligible) as last_claimed,
                MIN(c.period_key) FILTER (WHERE c.eligible) as first_claimed,
                COUNT(*) FILTER (WHERE NOT c.eligible) as skipped
            ")
            ->orderByRaw('COUNT(*) FILTER (WHERE c.eligible) DESC')
            ->get()
            ->map(function ($row) {
                $claimed = (int) $row->claimed;
                $remaining = max(0, EtiClaim::MAX_MONTHS - $claimed);

                return [
                    'employee_id'   => (int) $row->employee_id,
                    'name'          => $row->name ?: "#{$row->employee_id}",
                    'employee_number' => $row->employee_number,
                    'claimed'       => $claimed,
                    'remaining'     => $remaining,
                    'total'         => round((float) $row->total, 2),
                    'first_claimed' => $row->first_claimed,
                    'last_claimed'  => $row->last_claimed,
                    // Months the run looked at and decided against. Counted separately because a
                    // person with ten skipped months and two claims is a configuration problem,
                    // not an ETI success story.
                    'skipped'       => (int) $row->skipped,
                    'exhausted'     => $remaining === 0,
                    // Three months out is enough warning to budget for the PAYE going up.
                    'ending_soon'   => $remaining > 0 && $remaining <= 3,
                ];
            });

        $filtered = match ($filter) {
            'exhausted' => $rows->where('exhausted', true),
            'ending'    => $rows->where('ending_soon', true),
            default     => $rows->where('exhausted', false),
        };

        return Inertia::render('Modules/HR/Payroll/Eti/Index', [
            'rows'    => $filtered->values(),
            'filter'  => $filter,
            'maxMonths' => EtiClaim::MAX_MONTHS,
            'summary' => [
                'people'     => $rows->count(),
                'claiming'   => $rows->where('exhausted', false)->count(),
                'exhausted'  => $rows->where('exhausted', true)->count(),
                'ending_soon' => $rows->where('ending_soon', true)->count(),
                'total'      => round((float) $rows->sum('total'), 2),
            ],
        ]);
    }

    /** One person's month-by-month history, including the months that claimed nothing. */
    public function show(int $employee): Response
    {
        $history = $this->claims->historyFor(EtiClaim::HR, $employee);

        abort_if($history->isEmpty(), 404, 'No ETI history for this employee.');

        $person = DB::table('hr_people')->where('user_id', $employee)
            ->first(['first_name', 'surname', 'employee_number']);

        return Inertia::render('Modules/HR/Payroll/Eti/Show', [
            'employee' => [
                'id'     => $employee,
                'name'   => $person ? trim($person->first_name . ' ' . $person->surname) : "#{$employee}",
                'number' => $person->employee_number ?? null,
            ],
            'summary' => $this->claims->summaryFor(EtiClaim::HR, $employee),
            'maxMonths' => EtiClaim::MAX_MONTHS,
            'history' => $history->map(fn (EtiClaim $c) => [
                'period'      => $c->period_key,
                'from'        => $c->period_start?->toDateString(),
                'to'          => $c->period_end?->toDateString(),
                'claim_month' => $c->claim_month,
                'eligible'    => (bool) $c->eligible,
                'amount'      => (float) $c->amount,
                'remuneration' => (float) $c->monthly_remuneration,
                'age'         => $c->age,
                // Why a month claimed nothing. The reason is the whole point of keeping the row.
                'reason'      => $c->reason,
                'tax_year'    => $c->tax_year,
            ])->values(),
        ]);
    }

    /**
     * The tenant every figure is scoped to.
     *
     * Resolved to a concrete id rather than left null, so a platform admin without a current
     * company cannot aggregate one employer's incentive across every tenant on the box.
     */
    private function companyId(): int
    {
        return (int) (app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id()
            ?? auth()->user()?->company_id
            ?? -1);
    }
}
