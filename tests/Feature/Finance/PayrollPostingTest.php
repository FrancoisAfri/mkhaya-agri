<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinPosting;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Payslip;
use App\Modules\HR\Payroll\Services\PayrollLedgerPoster;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollPostingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '2330')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    /**
     * A user id for `created_by`, which the column requires.
     *
     * `User::query()` is company-scoped and this test sets no current company, so it returned null
     * on a database whose users all belong to one — and `created_by` is NOT NULL, so both tests in
     * this file died in setUp. Which mattered more than a red tick: the second of them is the guard
     * on posting idempotency, and it had never run far enough to check anything. The bug it exists
     * to catch was live in `FinancePoster` the whole time.
     */
    private function userId(): int
    {
        $existing = \App\Core\User\Models\User::withoutGlobalScopes()->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) \App\Core\User\Models\User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'payroll-' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1,
        ])->id;
    }

    private function makeRun(): PayrollRun
    {
        $uid = $this->userId();
        $run = PayrollRun::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Test', 'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(), 'status' => 'released', 'total_gross' => 10000,
            'total_deductions' => 3000, 'total_net' => 7000, 'employee_count' => 1, 'created_by' => $uid,
        ]);
        Payslip::create([
            'uuid' => (string) Str::uuid(), 'payroll_run_id' => $run->id, 'employee_id' => $uid, 'status' => 'finalised',
            'basic_salary' => 10000, 'gross_pay' => 10000, 'total_deductions' => 3000, 'net_pay' => 7000,
            'paye' => 1500, 'uif' => 100, 'pension' => 400, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        ]);

        return $run;
    }

    public function test_releasing_a_run_posts_a_balanced_salary_journal(): void
    {
        $run = $this->makeRun();
        app(PayrollLedgerPoster::class)->postRun($run);

        $posting = FinPosting::where('idempotency_key', 'payroll_run:' . $run->id)->first();
        $this->assertSame('posted', $posting->status);

        $journal = FinJournal::find($posting->journal_id);
        $this->assertEquals($journal->lines()->sum('debit'), $journal->lines()->sum('credit'));
        $this->assertEquals(10000.0, (float) $journal->lines()->sum('debit'));

        // Salaries debited, net + statutory credited (incl. the balancing plug).
        $byCode = $journal->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertEquals(10000.0, (float) $byCode['6100']->debit);
        $this->assertEquals(7000.0, (float) $byCode['2400']->credit);
        $this->assertEquals(1500.0, (float) $byCode['2300']->credit);
        $this->assertEquals(1000.0, (float) $byCode['2350']->credit); // 3000 − 1500 − 100 − 400
    }

    public function test_posting_a_run_twice_is_idempotent(): void
    {
        $run = $this->makeRun();
        $poster = app(PayrollLedgerPoster::class);
        $poster->postRun($run);
        $poster->postRun($run);

        $this->assertSame(1, FinJournal::where('source_ref', 'payroll_run:' . $run->id)->count());
    }
}
