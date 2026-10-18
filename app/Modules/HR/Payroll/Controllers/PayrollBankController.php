<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\NormalPayrollBankService;
use App\Modules\HR\Payroll\Support\SaBanks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Bank salary-file setup + generation for the normal single-company payroll. */
class PayrollBankController extends Controller
{
    public function __construct(private readonly NormalPayrollBankService $bank)
    {
    }

    public function settings(): InertiaResponse
    {
        return Inertia::render('Modules/Payroll/BankSettings', [
            'config' => $this->bank->config(),
            'banks'  => SaBanks::options(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bank'           => ['required', 'in:'.implode(',', SaBanks::codes())],
            'account_name'   => ['nullable', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:30'],
            'branch_code'    => ['required', 'string', 'max:15'],
            'user_code'      => ['nullable', 'string', 'max:15'],
        ]);
        $this->bank->saveConfig($data);

        return back()->with('success', 'Bank settings saved.');
    }

    /** Authenticated download of the salary file for a released run. */
    public function file(PayrollRun $run): Response
    {
        $file = $this->bank->generateForRun($run);

        return response($file['content'], 200, [
            'Content-Type'        => $file['format'] === 'csv' ? 'text/csv' : 'text/plain',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }
}
