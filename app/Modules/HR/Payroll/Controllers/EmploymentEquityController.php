<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Services\EmploymentEquityService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

/**
 * The Employment Equity return.
 *
 * A read-only screen: nothing here changes a payslip or a person. It reads what payroll and the
 * people records already hold and lays it out the way the EEA2 and EEA4 forms ask for it, so the
 * figures can be entered on the Department's portal and the working kept with the file.
 */
class EmploymentEquityController extends Controller
{
    public function __construct(private readonly EmploymentEquityService $equity) {}

    public function index(Request $request): \Inertia\Response
    {
        $year = (int) $request->integer('year', (int) date('n') >= 9 ? (int) date('Y') + 1 : (int) date('Y'));

        return Inertia::render('Modules/HR/Payroll/EmploymentEquity/Index', [
            'report' => $this->equity->report($year),
            'year'   => $year,
            'years'  => range((int) date('Y') + 1, (int) date('Y') - 4),
            'levels' => EmploymentEquityService::LEVELS,
            'races'  => EmploymentEquityService::RACES,
            'genders' => EmploymentEquityService::GENDERS,
        ]);
    }

    public function download(Request $request): Response
    {
        $year = (int) $request->integer('year', (int) date('Y'));
        $csv = $this->equity->toCsv($this->equity->report($year));

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="eea4-' . $year . '.csv"',
        ]);
    }
}
