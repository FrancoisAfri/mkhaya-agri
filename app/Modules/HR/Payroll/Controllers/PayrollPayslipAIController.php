<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Payslip;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollPayslipAIController extends Controller
{
    public function __construct(private PayrollAIService $aiService) {}

    public function explain(PayrollRun $run, Payslip $payslip): Response
    {
        $payslip->loadMissing('earningsLines');
        $explanation = $this->aiService->explainPayslipToEmployee($payslip);

        return Inertia::render('Modules/HR/Payroll/Payslips/Show', [
            'payslip'     => $payslip,
            'run'         => $run->only('id', 'uuid', 'ref_number', 'name', 'period_start', 'period_end'),
            'explanation' => $explanation,
        ]);
    }

    public function ask(Request $request, PayrollRun $run, Payslip $payslip): JsonResponse
    {
        $request->validate(['question' => ['required', 'string', 'max:500']]);

        $answer = $this->aiService->answerPayslipQuestion($payslip, $request->question);

        return response()->json(['answer' => $answer]);
    }
}
