<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use App\Modules\Finance\Accounting\Services\QrCodeService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PaymentQrTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '4100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    private function invoice(): \App\Modules\Finance\Accounting\Models\FinInvoice
    {
        $party = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'Q' . uniqid(), 'name' => 'QRCo']);

        return app(ArApService::class)->createInvoice([
            'party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Job', 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);
    }

    public function test_generates_an_eft_qr_by_default(): void
    {
        FinSetting::current()->fill(['show_payment_qr' => true, 'snapscan_merchant' => null])->save();
        $qr = app(QrCodeService::class)->paymentQr($this->invoice(), app(FinanceDocumentService::class)->company());

        $this->assertNotNull($qr);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qr['uri']);
        $this->assertStringContainsString('EFT', $qr['caption']);
    }

    public function test_uses_snapscan_when_configured(): void
    {
        FinSetting::current()->fill(['show_payment_qr' => true, 'snapscan_merchant' => 'mystore'])->save();
        $qr = app(QrCodeService::class)->paymentQr($this->invoice(), app(FinanceDocumentService::class)->company());

        $this->assertStringContainsString('SnapScan', $qr['caption']);
    }

    public function test_no_qr_when_disabled(): void
    {
        FinSetting::current()->fill(['show_payment_qr' => false])->save();
        $this->assertNull(app(QrCodeService::class)->paymentQr($this->invoice(), app(FinanceDocumentService::class)->company()));
    }
}
