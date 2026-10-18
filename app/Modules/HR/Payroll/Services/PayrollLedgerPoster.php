<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Services\AccountMap;
use App\Modules\Finance\Accounting\Services\FinancePoster;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Payslip;
use Illuminate\Support\Facades\Log;

/**
 * Turns a released payroll run into a General Ledger journal via the Finance
 * outbox. One summary journal per run:
 *
 *   Dr  6100 Salaries & Wages          (gross)
 *       Cr 2300 PAYE Payable           (Σ paye)
 *       Cr 2310 UIF Payable            (Σ uif)
 *       Cr 2330 Pension Payable        (Σ pension)
 *       Cr 2350 Other Payroll Deductions (plug = deductions − the above)
 *       Cr 2400 Salaries Payable (Net) (net)
 *
 * Idempotent (keyed on the run) — releasing twice posts once.
 */
class PayrollLedgerPoster
{
    /**
     * GL account codes, now the fallback rather than the whole answer.
     *
     * `AccountMap` is asked first — a tenant's own mapping, then the platform default seeded from
     * exactly these codes. So nothing changes for anybody who has not set one, and a tenant whose
     * chart of accounts does not happen to use 6100/2300/2310/2330/2350/2400 can finally say so
     * instead of having every payroll release post nowhere.
     */
    private const ACC = [
        'salaries' => '6100', 'paye' => '2300', 'uif' => '2310',
        'pension'  => '2330', 'other' => '2350', 'net'  => '2400',
    ];

    public function __construct(
        private FinancePoster $poster,
        private AccountMap $accounts,
    ) {
    }

    /** The mapped account for a payslip component, falling back to the constant it replaced. */
    private function account(string $key): ?string
    {
        return $this->accounts->codeFor('payroll', $key, self::ACC[$key] ?? null);
    }

    public function postRun(PayrollRun $run): void
    {
        $slips   = Payslip::where('payroll_run_id', $run->id)->get();
        $gross   = round((float) ($run->total_gross ?: $slips->sum('gross_pay')), 2);
        $net     = round((float) ($run->total_net   ?: $slips->sum('net_pay')), 2);
        $paye    = round((float) $slips->sum('paye'), 2);
        $uif     = round((float) $slips->sum('uif'), 2);
        $pension = round((float) $slips->sum('pension'), 2);

        if ($gross <= 0) {
            return; // nothing to post
        }

        // Plug so the entry always balances: whatever's deducted beyond the
        // itemised statutory lines lands in "Other Payroll Deductions".
        $other = round($gross - $net - $paye - $uif - $pension, 2);

        $credits = array_values(array_filter([
            ['account' => $this->account('paye'),    'amount' => $paye,    'narration' => 'PAYE'],
            ['account' => $this->account('uif'),     'amount' => $uif,     'narration' => 'UIF'],
            ['account' => $this->account('pension'), 'amount' => $pension, 'narration' => 'Pension'],
            ['account' => $this->account('other'),   'amount' => $other,   'narration' => 'Other deductions'],
            ['account' => $this->account('net'),     'amount' => $net,     'narration' => 'Net pay'],
        ], fn ($l) => $l['amount'] > 0.005 && $l['account'] !== null));

        $salaries = $this->account('salaries');

        /*
         * All of it, or none of it.
         *
         * A payroll journal with one leg missing does not balance, and posting the legs that do
         * resolve would put the difference somewhere nobody chose. Better to post nothing, say why,
         * and leave the run re-postable once the mapping is fixed — the release itself already
         * stands regardless of what the ledger does with it.
         */
        if ($salaries === null || $credits === [] || $this->unmapped($paye, $uif, $pension, $other, $net) !== []) {
            Log::warning('payroll: no ledger posting, accounts are not mapped', [
                'run'     => $run->id,
                'unmapped' => $this->unmapped($paye, $uif, $pension, $other, $net),
                'salaries' => $salaries,
                'fix'     => 'Map them under Accounting → Ledger accounts.',
            ]);

            return;
        }

        $event = new FinancialEvent(
            sourceModule: 'payroll',
            eventType: 'payroll.run.released',
            debits:  [['account' => $salaries, 'amount' => $gross, 'narration' => 'Gross salaries & wages']],
            credits: $credits,
            eventDate: $run->period_end ?? now(),
            sourceRef: 'payroll_run:' . $run->id,
            description: 'Payroll — ' . ($run->name ?: ('run #' . $run->id)),
            metadata: ['payroll_run_id' => $run->id, 'employee_count' => (int) $run->employee_count],
            idempotencyKey: 'payroll_run:' . $run->id,
        );

        $this->poster->emit($event);
    }

    /**
     * The components that carry money but have no account to carry it to.
     *
     * Named rather than counted: "3 unmapped" is not something anybody can act on, and the person
     * reading the log is the person who has to go and map them.
     *
     * @return list<string>
     */
    private function unmapped(float $paye, float $uif, float $pension, float $other, float $net): array
    {
        $amounts = compact('paye', 'uif', 'pension', 'other', 'net');

        return array_values(array_keys(array_filter(
            $amounts,
            fn ($amount, $key) => $amount > 0.005 && $this->account($key) === null,
            ARRAY_FILTER_USE_BOTH,
        )));
    }
}
