<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Support\SaBanks;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Generates the salary payment file the employer uploads into their internet
 * banking to pay net salaries. The layout follows the BankservAfrica/ACB EFT
 * standard (header → one credit per employee → employer contra/debit → trailer
 * with counts + hash totals); the selected bank picks the format profile. A
 * generic CSV is offered for banks that accept a simple import.
 *
 * The file content is plain (the bank importer must read it); callers store it
 * encrypted at rest and deliver it via authenticated download. Used by both the
 * bureau and the normal single-company payroll.
 */
class BankFileService
{
    /** Build a file from an already-resolved employer + beneficiary list. */
    public function buildFile(string $bank, array $employer, array $beneficiaries, ?Carbon $action, string $label): array
    {
        if (! SaBanks::exists($bank)) {
            throw ValidationException::withMessages(['bank' => 'Unknown bank.']);
        }
        if (blank($employer['account_number'] ?? null) || blank($employer['branch_code'] ?? null)) {
            throw ValidationException::withMessages(['bank' => "Capture the employer's funding account (number + branch) in setup."]);
        }
        if (empty($beneficiaries)) {
            throw ValidationException::withMessages(['run' => 'No payable employees with valid bank accounts.']);
        }

        $format = SaBanks::format($bank);
        $content = $format === 'csv'
            ? $this->csv($beneficiaries)
            : $this->acb($employer, $beneficiaries, $action ?? now());

        $total = array_sum(array_column($beneficiaries, 'cents')) / 100;
        $ext = $format === 'csv' ? 'csv' : 'txt';
        $stamp = ($action ?? now())->format('Y-m-d');

        return [
            'filename' => 'salaries_'.preg_replace('/\W+/', '-', strtolower($label)).'_'.$stamp.'.'.$ext,
            'content'  => $content,
            'format'   => $format,
            'count'    => count($beneficiaries),
            'total'    => round($total, 2),
        ];
    }

    /** Bureau pay run → bank file. */
    public function generate(BureauPayRun $run): array
    {
        $company = $run->company;
        if (! $run->isFinalised()) {
            throw ValidationException::withMessages(['run' => 'Finalise the run before generating the bank file.']);
        }
        if (blank($company->bank) || ! SaBanks::exists($company->bank)) {
            throw ValidationException::withMessages(['bank' => "Set the company's bank in setup before generating the file."]);
        }

        $beneficiaries = [];
        $skipped = [];
        foreach ($run->payslips()->with('employee')->get() as $slip) {
            $e = $slip->employee;
            if (! $e || blank($e->account_number) || blank($e->branch_code)) {
                $skipped[] = trim(($e?->fullName() ?? 'Employee').' — no bank account');
                continue;
            }
            if ((float) $slip->net_pay <= 0) {
                continue;
            }
            $beneficiaries[] = [
                'name'    => $e->account_holder ?: $e->fullName(),
                'branch'  => preg_replace('/\D/', '', (string) $e->branch_code),
                'account' => preg_replace('/\D/', '', (string) $e->account_number),
                'cents'   => (int) round((float) $slip->net_pay * 100),
                'ref'     => substr($company->name, 0, 20),
            ];
        }

        $employer = [
            'name'           => $company->name,
            'account_number' => $company->bank_account_number,
            'branch_code'    => $company->bank_branch_code,
            'user_code'      => $company->bank_user_code,
        ];
        $action = $run->pay_date ? Carbon::parse($run->pay_date) : $run->period_end;

        $file = $this->buildFile($company->bank, $employer, $beneficiaries, $action, $company->name) + ['skipped' => $skipped];

        // A payment file is the moment payroll becomes money. Who generated it, for how much, and
        // who was left out of it are all things a bureau has to be able to state afterwards — the
        // skipped names especially, because an employee missing from a run is usually discovered by
        // the employee.
        app(BureauAuditService::class)->run(
            $run,
            BureauAuditService::BANK_FILE_GENERATED,
            "Bank file generated for {$company->name} ({$company->bank}).",
            [
                'bank'          => $company->bank,
                'beneficiaries' => count($beneficiaries),
                'total'         => $file['total'] ?? null,
                'skipped'       => $skipped,
            ],
        );

        return $file;
    }

    // ── ACB / BankservAfrica EFT fixed-width layout ─────────────────────────────

    private function acb(array $employer, array $rows, Carbon $action): string
    {
        $lines = [];
        // Header (02): user code, creation date, action date, nominated account.
        $lines[] = '02'
            . $this->num($employer['user_code'] ?? '0', 8)
            . now()->format('Ymd')
            . $action->format('Ymd')
            . $this->num($employer['branch_code'], 6)
            . $this->num($employer['account_number'], 11)
            . '0001';

        $hash = 0;
        $totalCents = 0;
        foreach ($rows as $r) {
            // Standard transaction (10): homing branch, account, type(1), amount, ref, name.
            $lines[] = '10'
                . $this->num($r['branch'], 6)
                . $this->num($r['account'], 11)
                . '1'
                . $this->num((string) $r['cents'], 11)
                . $this->alpha($r['ref'], 20)
                . $this->alpha($r['name'], 30);
            $hash += (int) (($r['branch'] . $r['account']) % 1000000000000000);
            $totalCents += $r['cents'];
        }

        // Contra (92): employer debit for the batch total.
        $lines[] = '92'
            . $this->num($employer['branch_code'], 6)
            . $this->num($employer['account_number'], 11)
            . $this->num((string) $totalCents, 11)
            . $this->alpha('SALARIES', 20);

        // Trailer (94): record count, total credit, total debit, hash total.
        $lines[] = '94'
            . $this->num((string) (count($rows) + 3), 6)
            . $this->num((string) $totalCents, 15)
            . $this->num((string) $totalCents, 15)
            . $this->num((string) abs($hash), 15);

        return implode("\n", $lines) . "\n";
    }

    private function csv(array $rows): string
    {
        $out = "beneficiary_name,branch_code,account_number,amount,reference\n";
        foreach ($rows as $r) {
            $amount = number_format($r['cents'] / 100, 2, '.', '');
            $out .= '"'.str_replace('"', '', $r['name'])."\",{$r['branch']},{$r['account']},{$amount},\"{$r['ref']}\"\n";
        }

        return $out;
    }

    private function num(?string $v, int $len): string
    {
        $digits = preg_replace('/\D/', '', (string) $v);

        return str_pad(substr($digits, -$len), $len, '0', STR_PAD_LEFT);
    }

    private function alpha(?string $v, int $len): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', (string) $v));

        return str_pad(substr($clean, 0, $len), $len, ' ', STR_PAD_RIGHT);
    }
}
