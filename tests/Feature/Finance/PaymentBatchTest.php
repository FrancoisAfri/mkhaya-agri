<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\Finance\Accounting\Services\PaymentBatchService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PaymentBatchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '2100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
        Permission::firstOrCreate(['name' => PaymentBatchService::APPROVE_PERMISSION, 'guard_name' => 'web']);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function bill(): \App\Modules\Finance\Accounting\Models\FinInvoice
    {
        $supplier = FinParty::create(['kind' => 'supplier', 'source' => 'manual', 'code' => 'S' . uniqid(), 'name' => 'Vendor', 'bank_name' => 'FNB', 'bank_account' => '123456']);

        return app(ArApService::class)->createInvoice([
            'party_id' => $supplier->id, 'direction' => 'payable',
            'lines' => [['account_id' => FinAccount::where('code', '6200')->value('id'), 'description' => 'Rent', 'unit_price' => 800, 'tax_rate' => 0]],
        ]);
    }

    private function glBalance(string $code): float
    {
        $row = app(FinanceReportService::class)->accountBalances(null, now()->toDateString())->firstWhere('code', $code);
        return round((float) ($row['balance'] ?? 0), 2);
    }

    public function test_maker_checker_and_release_posts_payments(): void
    {
        $maker = User::first() ?? User::factory()->create();
        $checker = User::factory()->create();
        $checker->givePermissionTo(PaymentBatchService::APPROVE_PERMISSION);
        $this->actingAs($maker);

        $bill = $this->bill();
        $apBefore = $this->glBalance('2100'); // AP already includes this bill
        $svc = app(PaymentBatchService::class);

        $batch = $svc->create([$bill->id], '1100', now()->toDateString(), null);
        $this->assertEquals('draft', $batch->status);
        $this->assertEquals(800.0, (float) $batch->total);

        // Maker cannot approve their own batch.
        $this->assertThrows(fn () => $svc->approve($batch, $maker->fresh()), PostingException::class);

        $svc->approve($batch, $checker->fresh());
        $this->assertEquals('approved', $batch->fresh()->status);

        $svc->pay($batch->fresh('lines'));
        $this->assertEquals('paid', $batch->fresh()->status);
        $this->assertEquals('paid', $bill->fresh()->status);
        $this->assertEquals($apBefore - 800, $this->glBalance('2100')); // AP cleared
    }

    public function test_pain001_export_is_valid_xml(): void
    {
        $this->actingAs(User::first() ?? User::factory()->create());
        $bill = $this->bill();
        $batch = app(PaymentBatchService::class)->create([$bill->id], '1100', now()->toDateString(), null);

        $xml = app(PaymentBatchService::class)->pain001($batch);
        $this->assertStringContainsString('pain.001.001.03', $xml);
        $this->assertNotFalse(simplexml_load_string($xml)); // well-formed
    }
}
