<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayslip;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, token-based self-service payslip portal for a bureau employee (no
 * platform login). The token lists the employee's finalised payslips; each PDF
 * is still password-locked with their payslip key, so only they can open it.
 */
class BureauPayslipPortalController extends Controller
{
    public function show(string $token): InertiaResponse
    {
        $employee = $this->resolve($token);

        return Inertia::render('Modules/HR/Payroll/Bureau/PayslipPortal', [
            'token'    => $token,
            'employee' => ['name' => $employee->fullName(), 'company' => $employee->company?->name],
            'payslips' => $employee->payslips()
                ->where('status', 'finalised')
                // Either place the bytes may be. Filtering on the column alone would hide every
                // payslip whose PDF has moved to the shared disk — the employee would open the
                // portal and find their history empty.
                ->where(fn ($q) => $q->whereNotNull('pdf_data')->orWhereNotNull('storage_path'))
                ->with('run:id,name,period_start,period_end')
                ->latest()->get()
                ->map(fn (BureauPayslip $p) => [
                    'uuid'   => $p->uuid,
                    'run'    => $p->run?->name,
                    'period' => $p->run ? ($p->run->period_start?->format('d M Y').' – '.$p->run->period_end?->format('d M Y')) : null,
                    'net'    => (float) $p->net_pay,
                ]),
        ]);
    }

    public function download(string $token, string $payslipUuid): Response
    {
        $employee = $this->resolve($token);
        $payslip = $employee->payslips()->where('uuid', $payslipUuid)->where('status', 'finalised')->first();
        abort_if($payslip === null || ! $payslip->hasBytes(), 404);

        return response((string) $payslip->bytes(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="payslip-'.$payslip->uuid.'.pdf"',
        ]);
    }

    private function resolve(string $token): BureauEmployee
    {
        $employee = BureauEmployee::where('portal_token', $token)->with('company')->first();
        if (! $employee) {
            throw new NotFoundHttpException('Payslip portal not found.');
        }

        return $employee;
    }
}
