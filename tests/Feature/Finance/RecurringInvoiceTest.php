<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\RecurringInvoiceService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '4100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        // Ensure the year's periods are open (a due schedule may date into a past month).
        $year = (int) now()->year;
        for ($p = 1; $p <= 12; $p++) {
            if (! FinFiscalPeriod::whereNull('company_id')->where('fiscal_year', $year)->where('period_no', $p)->exists()) {
                $s = Carbon::create($year, $p, 1);
                FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => $year, 'period_no' => $p,
                    'start_date' => $s->toDateString(), 'end_date' => $s->copy()->endOfMonth()->toDateString(), 'status' => 'open']);
            }
        }
    }

    private function schedule(string $start): \App\Modules\Finance\Accounting\Models\FinRecurringInvoice
    {
        $party = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'R' . uniqid(), 'name' => 'Subscriber']);

        return app(RecurringInvoiceService::class)->save([
            'party_id' => $party->id, 'name' => 'Monthly retainer', 'frequency' => 'monthly', 'interval' => 1,
            'start_date' => $start,
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Retainer', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);
    }

    public function test_generate_one_creates_a_posted_invoice_and_advances(): void
    {
        $r = $this->schedule(now()->startOfMonth()->toDateString());
        $before = $r->next_run_date->copy();

        $invoice = app(RecurringInvoiceService::class)->generateOne($r);

        $this->assertInstanceOf(FinInvoice::class, $invoice);
        $this->assertNotNull($invoice->journal_id);           // posted
        $this->assertEquals(1150.0, (float) $invoice->total); // 1000 + 15%
        $this->assertEquals($before->addMonth()->toDateString(), $r->fresh()->next_run_date->toDateString());
        $this->assertEquals(1, $r->fresh()->generated_count);
    }

    public function test_generate_due_catches_up_missed_cycles(): void
    {
        // Started 2 months ago → two cycles are due.
        $r = $this->schedule(now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString());

        $count = app(RecurringInvoiceService::class)->generateDue();

        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertTrue($r->fresh()->next_run_date->gt(now()->subDay()));
    }
}
