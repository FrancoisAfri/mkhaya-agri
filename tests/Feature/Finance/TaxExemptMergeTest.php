<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ArApService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaxExemptMergeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    public function test_tax_exempt_customer_is_charged_no_vat(): void
    {
        $party = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'X' . uniqid(), 'name' => 'NPO', 'tax_exempt' => true]);

        $inv = app(ArApService::class)->createInvoice([
            'party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Job', 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);

        $this->assertEquals(0.0, (float) $inv->tax_total); // VAT forced to zero
        $this->assertEquals(1000.0, (float) $inv->total);
        $this->assertEquals(0.0, (float) $inv->lines->first()->tax_rate);
    }

    public function test_merge_moves_documents_and_deletes_duplicate(): void
    {
        $svc = app(ArApService::class);
        $survivor = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'A' . uniqid(), 'name' => 'Acme']);
        $dup = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'A' . uniqid(), 'name' => 'Acme (dup)']);

        $inv = $svc->createInvoice(['party_id' => $dup->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'X', 'unit_price' => 100, 'tax_rate' => 0]]]);

        // Merge via the controller's DB reassignment (mirror of the action).
        DB::transaction(function () use ($survivor, $dup) {
            foreach (['fin_invoices', 'fin_payments', 'fin_collection_activities', 'fin_recurring_invoices', 'fin_payment_batch_lines', 'fin_quotes'] as $t) {
                DB::table($t)->where('party_id', $dup->id)->update(['party_id' => $survivor->id]);
            }
            $dup->delete();
        });

        $this->assertNull(FinParty::find($dup->id));                    // duplicate gone
        $this->assertEquals($survivor->id, $inv->fresh()->party_id);    // invoice reassigned
    }
}
