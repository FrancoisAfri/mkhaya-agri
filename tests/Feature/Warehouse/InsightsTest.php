<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Jobs\GenerateReport;
use App\Modules\Operations\WarehouseManagement\Notifications\ReportReadyNotification;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseReports;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/** WMS-180..185: the dashboards and the report exports, with permissions applied the same way to both. */
class InsightsTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('DC', ['is_default' => true, 'is_wms_managed' => true]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $bin = app(LocationService::class)->create($this->wh, ['code' => 'A1']);
            // A name a spreadsheet would run as a formula.
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'JAM', 'name' => '=HYPERLINK("http://x")', 'uom' => 'ea', 'standard_cost' => 20]);
            app(StockLedger::class)->receive($this->item->fresh(), 12, 20, $this->wh, ['bin_id' => $bin->id, 'reference' => 'GRN-1']);
        });
    }

    public function test_the_operations_dashboard_shows_the_day_and_what_is_critical(): void
    {
        $this->actingAs($this->viewer)->get(route('warehouse.dashboard'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('today.available', 12)->has('critical', 6)->where('today.counts.open', 0));
    }

    public function test_dashboards_follow_the_same_permissions_and_hide_money_without_the_accounting_grant(): void
    {
        $this->actingAs($this->viewer)->get(route('warehouse.insights.dashboard', 'inventory'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('data.total.qty', 12)->where('data.total.value', null)->where('with_values', false)->where('available', ['inventory', 'suppliers']));
        $this->actingAs($this->viewer)->get(route('warehouse.insights.dashboard', 'suppliers'))->assertOk();
        $this->actingAs($this->viewer)->get(route('warehouse.insights.dashboard', 'protection'))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('warehouse.insights.dashboard', 'executive'))->assertForbidden();
        $this->actingAs($this->outsider)->get(route('warehouse.insights.dashboard', 'inventory'))->assertForbidden();

        $this->actingAs($this->manager)->get(route('warehouse.insights.dashboard', 'inventory'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('data.total.value', 240)->where('data.ageing.0.qty', 12));
        $this->actingAs($this->manager)->get(route('warehouse.insights.dashboard', 'protection'))->assertOk()->assertInertia(fn ($p) => $p->has('data.effectiveness'));
        $this->actingAs($this->manager)->get(route('warehouse.insights.dashboard', 'executive'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('data.inventory_value', 240)->has('data.stockout_exposure'));
    }

    public function test_an_export_carries_its_filters_and_time_shows_values_only_when_allowed_and_neutralises_formulas(): void
    {
        $csv = $this->actingAs($this->viewer)->post(route('warehouse.reports.run'), ['report' => 'stock_on_hand', 'format' => 'csv', 'warehouse_id' => $this->wh->id])->assertOk();
        $body = $csv->getContent();
        $lines = array_map('str_getcsv', explode("\n", trim($body)));
        $this->assertStringStartsWith('Stock on hand — generated ', $lines[0][0]);
        $this->assertSame('Warehouse: DC', $lines[1][0]);
        $this->assertNotContains('Value', $lines[3]);
        $this->assertSame("'=HYPERLINK(\"http://x\")", $lines[4][1]);
        $run = DB::table('wms_report_runs')->where('requested_by', $this->viewer->id)->sole();
        $this->assertSame(['warehouse_id' => $this->wh->id], json_decode($run->parameters, true));
        $this->assertNotNull($run->generated_at);
        $this->assertFalse((bool) $run->sensitive);

        $this->actingAs($this->manager)->post(route('warehouse.reports.run'), ['report' => 'stock_on_hand', 'format' => 'xlsx'])->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($this->manager)->post(route('warehouse.reports.run'), ['report' => 'movements', 'format' => 'pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $sheet = DB::table('wms_report_runs')->where('requested_by', $this->manager->id)->where('format', 'XLSX')->sole();
        $this->assertTrue((bool) $sheet->with_values);
        $this->assertTrue((bool) $sheet->sensitive);
        $this->assertSame(1, (int) DB::table('activity_log')->where('event', 'downloaded')->where('causer_id', $this->manager->id)->count(), 'sensitive downloads are audited');

        $this->actingAs($this->viewer)->post(route('warehouse.reports.run'), ['report' => 'losses', 'format' => 'csv'])->assertForbidden();
        $this->actingAs($this->viewer)->get(route('warehouse.reports.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('catalogue', fn ($c) => ! collect($c)->contains('key', 'losses') && ! collect($c)->contains('key', 'excess')));
    }

    public function test_a_background_export_is_built_by_the_job_notifies_and_downloads_only_for_its_requester(): void
    {
        Queue::fake();
        Notification::fake();
        $this->actingAs($this->manager)->post(route('warehouse.reports.run'), ['report' => 'stock_on_hand', 'format' => 'csv', 'background' => 1])
            ->assertRedirect(route('warehouse.reports.index'));
        Queue::assertPushed(GenerateReport::class);
        $run = DB::table('wms_report_runs')->where('requested_by', $this->manager->id)->sole();
        $this->assertSame('QUEUED', $run->status);

        $this->asCompany($this->company, fn () => (new GenerateReport($run->id))->handle(app(WarehouseReports::class)));
        $run = DB::table('wms_report_runs')->where('id', $run->id)->first();
        $this->assertSame('COMPLETE', $run->status);
        $this->assertSame(1, (int) $run->rows);
        Notification::assertSentTo($this->manager, ReportReadyNotification::class);

        $this->actingAs($this->viewer)->get(route('warehouse.reports.download', $run->uuid))->assertNotFound();
        $this->actingAs($this->manager)->get(route('warehouse.reports.download', $run->uuid))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // The grant is checked at download, not only when the export was asked for.
        $this->manager->syncPermissions([]);
        foreach ($this->manager->roles as $role) {
            $role->revokePermissionTo(RoleCatalog::ACCOUNTING);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->manager->fresh())->get(route('warehouse.reports.download', $run->uuid))->assertForbidden();
    }
}
