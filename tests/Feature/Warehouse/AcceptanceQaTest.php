<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Services\AssistantDesk;
use App\Modules\Operations\WarehouseManagement\Services\EpcisMapping;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance cases of the QA matrix (Part D §31) not already proven by a feature test elsewhere:
 * QA-013, QA-014, QA-021, QA-023 and QA-025. The full matrix -- every case with its test and
 * evidence -- is docs/warehouse/qa-matrix.md, produced by `php artisan wms:qa-evidence`.
 */
class AcceptanceQaTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('QA', ['is_wms_managed' => true, 'timezone' => 'Africa/Johannesburg']);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $this->bin = app(LocationService::class)->create($this->wh, ['code' => 'Q1']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'QAT', 'name' => 'QA item', 'uom' => 'ea', 'standard_cost' => 30]);
        });
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    /** QA-013: while accounting cannot post, the stock does not move either; once it can, the same work succeeds once. */
    public function test_qa013_when_accounting_cannot_post_the_stock_does_not_move_and_the_work_recovers(): void
    {
        $period = FinFiscalPeriod::forDate(now(), null);
        $period->update(['status' => 'closed']);
        try {
            $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 5, 30, $this->wh, ['bin_id' => $this->bin->id, 'idempotency_key' => 'grn-77']));
            $this->fail('Stock moved while the books could not take the posting.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('fin_stock_movements')->where('item_id', $this->item->id)->count());
        $this->assertSame(0.0, (float) $this->item->fresh()->qty_on_hand);

        $period->update(['status' => 'open']);
        $m = $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 5, 30, $this->wh, ['bin_id' => $this->bin->id, 'idempotency_key' => 'grn-77']));
        $this->assertNotNull($m->journal_id, 'the retried receipt is posted to the books');
        $this->assertSame(5.0, (float) $this->item->fresh()->qty_on_hand);
    }

    /** QA-014: a posting retried after a timeout makes one movement and one journal. */
    public function test_qa014_a_retried_posting_makes_one_movement_and_one_journal(): void
    {
        $a = $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 4, 30, $this->wh, ['bin_id' => $this->bin->id, 'idempotency_key' => 'po-9:line-1']));
        $b = $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 4, 30, $this->wh, ['bin_id' => $this->bin->id, 'idempotency_key' => 'po-9:line-1']));
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, DB::table('fin_stock_movements')->where('item_id', $this->item->id)->count());
        $this->assertSame(1, DB::table('fin_journals')->where('id', $a->journal_id)->count());
        $this->assertSame(1, DB::table('fin_journals')->where('source_ref', DB::table('fin_journals')->where('id', $a->journal_id)->value('source_ref'))->count());
        $this->assertSame(4.0, (float) $this->item->fresh()->qty_on_hand);
    }

    /** QA-021: a warehouse moving time zone shows its history in the new zone without changing when anything happened. */
    public function test_qa021_changing_a_warehouses_time_zone_keeps_every_historic_instant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 21:30:00', 'UTC'));
        $m = $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 2, 30, $this->wh, ['bin_id' => $this->bin->id]));
        Carbon::setTestNow();
        $stored = DB::table('fin_stock_movements')->where('id', $m->id)->value('created_at');
        $instant = Carbon::parse($stored)->utc()->toIso8601String();

        $this->in(fn () => app(WarehouseDirectory::class)->save(['timezone' => 'Asia/Dubai'], $this->wh->fresh()));
        $this->assertSame($stored, DB::table('fin_stock_movements')->where('id', $m->id)->value('created_at'), 'the stored instant is untouched');

        $event = collect($this->in(fn () => app(EpcisMapping::class)->document(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-14')))['epcisBody']['eventList'])->firstWhere('bizStep', 'receiving');
        $this->assertSame('+04:00', $event['eventTimeZoneOffset'], 'shown in the warehouse\'s new zone');
        $this->assertSame($instant, Carbon::parse($event['eventTime'])->utc()->toIso8601String(), 'and it is still the same moment');
    }

    /** QA-023: an export the person may not run is refused, and the refusal is in the audit ledger. */
    public function test_qa023_an_unauthorised_export_is_refused_and_recorded(): void
    {
        $this->actingAs($this->viewer)->post(route('warehouse.reports.run'), ['report' => 'losses', 'format' => 'csv'])->assertForbidden();
        $this->assertTrue(DB::table('wms_audit_events')->where('company_id', $this->company->id)->where('action', 'export.denied')->where('entity_ref', 'losses')
            ->where('actor_id', $this->viewer->id)->exists());
        $this->assertSame(0, DB::table('wms_report_runs')->where('requested_by', $this->viewer->id)->count());
    }

    /** QA-025: the assistant cannot approve a write-off -- or anything -- and the attempt is recorded. */
    public function test_qa025_the_assistant_cannot_approve_a_write_off_and_the_attempt_is_recorded(): void
    {
        config(['warehouse.assistant_narration' => false]);
        $this->actingAs($this->manager);
        $q = $this->in(fn () => app(AssistantDesk::class)->ask('Which stock should I write off?', $this->manager));
        foreach (['APPROVE_WRITE_OFF', 'POST_ADJUSTMENT'] as $kind) {
            try {
                $this->in(fn () => app(AssistantDesk::class)->draft($q->uuid, 0, $kind, $this->manager));
                $this->fail("The assistant accepted {$kind}.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('only drafts', $e->getMessage());
            }
        }
        $this->assertSame(2, DB::table('wms_audit_events')->where('company_id', $this->company->id)->where('action', 'assistant.refused')->count());
        $this->assertTrue($this->manager->can(RoleCatalog::APPROVE_LOSS), 'refused even for somebody who could approve it themselves');
    }
}
