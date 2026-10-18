<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\DunningService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The nightly collections run survives one bad invoice.
 *
 * From the production log: "Attempt to read property 'language' on null" at DunningService:55,
 * which failed finance:send-reminders with exit code 1 — for that tenant and every tenant after it.
 *
 * The guard against a party with no contact details was pickChannel(), which is null-safe and
 * returns null so the caller can skip. It was only consulted when the caller left the channel open,
 * and runLadder() always passes the ladder step's channel — so on the one path that actually runs
 * in production, the guard was bypassed and the next line dereferenced the null.
 */
class DunningResilienceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    private function overdueInvoiceFor(FinParty $party): FinInvoice
    {
        return app(ArApService::class)->createInvoice([
            'party_id' => $party->id, 'direction' => 'receivable',
            'invoice_date' => now()->subDays(60), 'due_date' => now()->subDays(45),
            'lines' => [[
                'account_id' => FinAccount::where('code', '4100')->value('id'),
                'description' => 'Job', 'unit_price' => 1000, 'tax_rate' => 0,
            ]],
        ]);
    }

    private function party(array $attributes = []): FinParty
    {
        return FinParty::create($attributes + [
            'kind' => 'customer', 'source' => 'manual',
            'code' => 'D' . uniqid(), 'name' => 'Late Payer',
        ]);
    }

    private function dunning(): DunningService
    {
        return app(DunningService::class);
    }

    /**
     * Reminders on, and SMS allowed alongside email.
     *
     * channels() defaults to email only, so without this a phone-only customer is genuinely
     * unreachable and no_contact is the right answer rather than a bug — which is what the first
     * version of these tests got wrong about their own premise.
     */
    private function enableReminders(array $channels = ['email', 'sms']): void
    {
        $settings = FinSetting::current(null);
        $settings->fill(['reminders_enabled' => true, 'reminder_channels' => $channels])->save();
    }

    public function test_an_invoice_whose_party_is_invisible_is_skipped_rather_than_fatal(): void
    {
        /*
         * fin_invoices.party_id is NOT NULL, so this is not a missing reference — FinParty is
         * tenant-scoped, and an invoice pointing at a party the current tenant cannot see resolves
         * the relation to null. That is the production condition, reproduced by pointing the
         * invoice at a party id that does not resolve.
         */
        $invoice = $this->overdueInvoiceFor($this->party(['email' => 'late@x.io']));
        DB::table('fin_invoices')->where('id', $invoice->id)->update(['party_id' => 99999999]);

        $result = $this->dunning()->sendReminder($invoice->fresh(), 'email', 'firm', 3);

        $this->assertFalse($result['sent']);
        $this->assertSame('no_party', $result['reason']);
    }

    public function test_an_explicit_channel_no_longer_bypasses_the_contact_check(): void
    {
        // The bug in one line: with the channel supplied, pickChannel() never ran, and a party with
        // no email at all went straight past the no_contact skip.
        $invoice = $this->overdueInvoiceFor($this->party(['email' => null, 'phone' => null]));

        $result = $this->dunning()->sendReminder($invoice, 'email', 'firm', 3);

        $this->assertFalse($result['sent']);
        $this->assertSame('no_contact', $result['reason']);
    }

    public function test_a_customer_with_only_a_phone_is_still_chased(): void
    {
        /*
         * The ladder asked for email and this customer has none. Skipping them would be the easy
         * answer and the wrong one — they are reachable, and a debtor nobody chases is the point of
         * the whole feature. Falling back is what pickChannel() does for the open-channel path, so
         * both paths now behave the same way.
         */
        $this->enableReminders(['email', 'sms']);
        $invoice = $this->overdueInvoiceFor($this->party(['email' => null, 'phone' => '0820000000']));

        $result = $this->dunning()->sendReminder($invoice, 'email', 'firm', 3);

        $this->assertNotSame('no_contact', $result['reason'] ?? null);
        $this->assertSame('sms', $result['channel']);
    }

    public function test_a_reachable_customer_is_unaffected(): void
    {
        $invoice = $this->overdueInvoiceFor($this->party(['email' => 'late@x.io']));

        $result = $this->dunning()->sendReminder($invoice, 'email', 'firm', 3);

        // Whether the mail actually leaves depends on the transport, which is not what this checks.
        // What matters is that it got as far as trying rather than being refused.
        $this->assertNotSame('no_contact', $result['reason'] ?? null);
        $this->assertNotSame('no_party', $result['reason'] ?? null);
    }

    public function test_one_unsendable_invoice_does_not_end_the_nightly_run(): void
    {
        // The consequence that mattered: the command exited 1, so this company's remaining debtors
        // and every company after it were not chased at all that night.
        $this->enableReminders();

        $broken = $this->overdueInvoiceFor($this->party(['email' => 'a@x.io']));
        DB::table('fin_invoices')->where('id', $broken->id)->update(['party_id' => 99999999]);

        $this->overdueInvoiceFor($this->party(['email' => 'b@x.io']));

        // The whole point: this returns rather than throwing, and the healthy invoice is still
        // considered. Before the fix the broken one raised and the command exited 1.
        $result = $this->dunning()->runLadder(null);

        $this->assertGreaterThanOrEqual(2, $result['sent'] + $result['skipped']);
    }
}
