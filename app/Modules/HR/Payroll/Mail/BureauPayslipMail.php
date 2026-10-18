<?php

namespace App\Modules\HR\Payroll\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Emails a payslip to an employee with the PDF attached. The attachment is the
 * password-protected (encrypted) PDF, so only the employee — who holds the
 * payslip key — can open it, even if the email is forwarded or intercepted.
 */
class BureauPayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $companyName,
        private readonly string $employeeName,
        private readonly string $period,
        private readonly string $encryptedPdf,
    ) {
    }

    public function build(): self
    {
        return $this->subject("Payslip — {$this->companyName} ({$this->period})")
            ->html(
                "<p>Hi {$this->employeeName},</p>"
                ."<p>Your payslip for {$this->period} is attached. It is password-protected — "
                ."open it with your payslip key (provided separately by your employer).</p>"
                ."<p>{$this->companyName}</p>"
            )
            ->attachData($this->encryptedPdf, 'payslip.pdf', ['mime' => 'application/pdf']);
    }
}
