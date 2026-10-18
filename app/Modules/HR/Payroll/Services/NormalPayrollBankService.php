<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\PayrollSetting;
use App\Modules\HR\Payroll\Services\Bureau\BankFileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank salary file for the normal single-company payroll. The employer's bank +
 * funding account live in payroll_settings.settings_json['bank_file']; employee
 * banking comes from hr_people. Reuses the shared BankFileService.
 */
class NormalPayrollBankService
{
    public function __construct(private readonly BankFileService $files)
    {
    }

    private function settings(): PayrollSetting
    {
        return PayrollSetting::query()->latest('id')->firstOrFail();
    }

    /** Current employer bank-file configuration. */
    public function config(): array
    {
        return ($this->settings()->settings_json['bank_file'] ?? []);
    }

    public function saveConfig(array $data): void
    {
        $setting = $this->settings();
        $json = $setting->settings_json ?? [];
        $json['bank_file'] = array_intersect_key($data, array_flip([
            'bank', 'account_name', 'account_number', 'branch_code', 'user_code',
        ]));
        $setting->update(['settings_json' => $json]);
    }

    /** Build the bank file for a released run. @return array{filename,content,format,count,total,skipped} */
    public function generateForRun(PayrollRun $run): array
    {
        $cfg = $this->config();
        if (blank($cfg['bank'] ?? null)) {
            throw ValidationException::withMessages(['bank' => 'Configure the company bank under payroll settings first.']);
        }

        $rows = DB::table('payslips as p')
            ->leftJoin('hr_people as h', 'h.user_id', '=', 'p.employee_id')
            ->where('p.payroll_run_id', $run->id)
            ->whereNull('p.deleted_at')
            ->get(['p.net_pay', 'h.account_number', 'h.branch_name', 'h.account_holder_name', 'h.first_name', 'h.surname']);

        $beneficiaries = [];
        $skipped = [];
        foreach ($rows as $r) {
            if (blank($r->account_number) || (float) $r->net_pay <= 0) {
                $skipped[] = trim(($r->account_holder_name ?: trim($r->first_name.' '.$r->surname)) ?: 'Employee').' — no bank account';
                continue;
            }
            $beneficiaries[] = [
                'name'    => $r->account_holder_name ?: trim($r->first_name.' '.$r->surname),
                'branch'  => preg_replace('/\D/', '', (string) $r->branch_name),
                'account' => preg_replace('/\D/', '', (string) $r->account_number),
                'cents'   => (int) round((float) $r->net_pay * 100),
                'ref'     => 'SALARY',
            ];
        }

        $employer = [
            'name'           => 'Company',
            'account_number' => $cfg['account_number'] ?? null,
            'branch_code'    => $cfg['branch_code'] ?? null,
            'user_code'      => $cfg['user_code'] ?? null,
        ];

        return $this->files->buildFile($cfg['bank'], $employer, $beneficiaries, $run->period_end ? \Illuminate\Support\Carbon::parse($run->period_end) : null, 'company-payroll')
            + ['skipped' => $skipped];
    }
}
