<?php

namespace App\Modules\HR\Payroll\Services;

use Illuminate\Support\Str;
use Mpdf\Mpdf;

/**
 * Renders payslip PDFs and locks them so only the employee can open them. A
 * payslip "key" is the PDF open-password: the same key protects the copy stored
 * on the employee's profile and the copy emailed by a bureau, and is required to
 * open either. Used by both normal payroll and the bureau.
 */
class SecurePayslipService
{
    /** Generate a fresh, human-enterable payslip key (the PDF open-password). */
    public function generateKey(): string
    {
        // Avoid ambiguous characters; 10 chars is strong enough for a payslip lock.
        return strtoupper(Str::password(10, letters: true, numbers: true, symbols: false));
    }

    /** Render payslip HTML to a plain (unlocked) PDF. */
    public function render(string $html): string
    {
        $mpdf = $this->mpdf();
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Render payslip HTML to a password-protected PDF. The employee needs $key to
     * open it; an unguessable owner password prevents permission tampering.
     */
    public function renderProtected(string $html, string $key): string
    {
        $mpdf = $this->mpdf();
        $mpdf->SetProtection(['print'], $key, 'owner-'.Str::random(24));
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function mpdf(): Mpdf
    {
        return new Mpdf([
            'tempDir' => storage_path('app/mpdf'),
            'format'  => 'A4',
        ]);
    }
}
