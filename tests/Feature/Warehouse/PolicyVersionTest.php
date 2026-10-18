<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use Illuminate\Support\Facades\DB;

/** WMS-240/241: policies are checked, versioned, activated by name, restorable -- and never rewrite what was posted. */
class PolicyVersionTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('PV', ['is_wms_managed' => true, 'timezone' => 'Africa/Johannesburg']);
    }

    private function draft(array $p, int $expectVersion = 0)
    {
        return $this->actingAs($this->manager)->post(route('warehouse.layout.warehouses.policies.draft', $this->wh->uuid), ['policies' => $p, 'note' => 'test']);
    }

    public function test_a_policy_change_is_checked_drafted_activated_and_restorable(): void
    {
        $this->draft(['sla_dispatch_hours' => 2, 'sla_risk_minutes' => 180])->assertSessionHasErrors('refused');
        $this->draft(['gln' => '6009876543210'])->assertSessionHasErrors('refused');
        $this->draft(['rotation' => 'NEWEST'])->assertSessionHasErrors('refused');
        $this->actingAs($this->manager)->post(route('warehouse.layout.warehouses.policies.draft', $this->wh->uuid), ['policies' => ['rotation' => 'FIFO'], 'check_only' => true])
            ->assertSessionHas('policy_check', fn ($c) => $c['changes']['rotation'] === ['from' => 'FEFO', 'to' => 'FIFO'] && $c['errors'] === []);
        $this->assertSame(0, DB::table('wms_policy_versions')->where('warehouse_id', $this->wh->id)->count(), 'checking writes nothing');

        $this->draft(['rotation' => 'FIFO', 'expiry_alert_days' => '120, 60, 14'])->assertSessionHasNoErrors();
        $this->assertSame('FEFO', $this->wh->fresh()->policies['rotation'] ?? 'FEFO', 'a draft is not in force');
        $draft = DB::table('wms_policy_versions')->where('warehouse_id', $this->wh->id)->where('status', 'DRAFT')->sole();
        $this->assertSame(2, (int) $draft->version, 'version 1 records the starting point');

        $this->actingAs($this->viewer)->post(route('warehouse.layout.warehouses.policies.act', [$this->wh->uuid, $draft->id]), ['action' => 'activate'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('warehouse.layout.warehouses.policies.act', [$this->wh->uuid, $draft->id]), ['action' => 'activate'])->assertSessionHasNoErrors();
        $p = $this->wh->fresh()->policies;
        $this->assertSame('FIFO', $p['rotation']);
        $this->assertSame([120, 60, 14], $p['expiry_alert_days']);
        $this->assertSame($this->manager->id, (int) DB::table('wms_policy_versions')->where('id', $draft->id)->value('activated_by'));
        $this->assertTrue(DB::table('wms_audit_events')->where('action', 'warehouse.changed')->where('entity_ref', 'PV')->exists());

        $first = DB::table('wms_policy_versions')->where('warehouse_id', $this->wh->id)->where('version', 1)->sole();
        $this->actingAs($this->manager)->post(route('warehouse.layout.warehouses.policies.act', [$this->wh->uuid, $first->id]), ['action' => 'restore'])->assertSessionHasNoErrors();
        $this->assertSame('FEFO', $this->wh->fresh()->policies['rotation']);
        $this->assertSame(3, (int) DB::table('wms_policy_versions')->where('warehouse_id', $this->wh->id)->where('status', 'ACTIVE')->value('version'), 'a restore is a new version');
        $this->assertSame(1, DB::table('wms_policy_versions')->where('warehouse_id', $this->wh->id)->where('status', 'ACTIVE')->count());
    }

    public function test_the_warehouse_form_versions_its_changes_and_keeps_every_policy_key(): void
    {
        $this->actingAs($this->manager)->patch(route('warehouse.layout.warehouses.update', $this->wh->uuid), ['code' => 'PV', 'name' => 'Policy warehouse', 'warehouse_type' => 'GENERAL',
            'timezone' => 'Africa/Johannesburg', 'is_wms_managed' => true, 'policies' => ['carrying_cost_pct' => 18, 'restrict_to_staff' => false, 'batch_size' => 12]])->assertSessionHasNoErrors();
        $p = $this->wh->fresh()->policies;
        $this->assertSame(18.0, (float) $p['carrying_cost_pct'], 'keys services read are no longer dropped on save');
        $this->assertSame(12, $p['batch_size']);
        $this->assertSame(1, DB::table('wms_policy_versions')->where('warehouse_id', $this->wh->id)->where('status', 'ACTIVE')->count());
        $this->actingAs($this->manager)->post(route('warehouse.integrations.standards.gln', $this->wh->uuid), ['gln' => '6009876543219'])->assertSessionHasNoErrors();
        $this->assertSame('6009876543219', $this->wh->fresh()->policies['gln']);
        $this->assertSame(18.0, (float) $this->wh->fresh()->policies['carrying_cost_pct'], 'setting the GLN keeps the other policies');
        $this->actingAs($this->viewer)->get(route('warehouse.layout.warehouses.policies', $this->wh->uuid))->assertOk()->assertInertia(fn ($pg) => $pg->where('can.manage', false));
    }

    public function test_a_policy_change_does_not_rewrite_what_was_already_decided_or_posted(): void
    {
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            $bin = app(LocationService::class)->create($this->wh, ['code' => 'P1']);
            $item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'PEN', 'name' => 'Pen', 'uom' => 'ea', 'standard_cost' => 2]);
            app(StockLedger::class)->receive($item->fresh(), 10, 2, $this->wh, ['bin_id' => $bin->id]);
            // Backdated beyond the policy's 7 days: waits for approval, and says why.
            app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $item->id, 'bin_id' => $bin->id, 'qty' => -1,
                'reason_code_id' => ReasonCode::query()->where('code', 'DAMAGED')->value('id'), 'occurred_at' => now()->subDays(10)->toDateString()], $this->manager);
        });
        $before = DB::table('wms_stock_adjustments')->where('company_id', $this->company->id)->get(['status', 'approval_reasons'])->toArray();
        $movements = DB::table('fin_stock_movements')->where('warehouse_id', $this->wh->id)->get()->toArray();

        $this->draft(['max_backdate_days' => 30, 'rotation' => 'FIFO'])->assertSessionHasNoErrors();
        $v = DB::table('wms_policy_versions')->where('warehouse_id', $this->wh->id)->where('status', 'DRAFT')->sole();
        $this->actingAs($this->manager)->post(route('warehouse.layout.warehouses.policies.act', [$this->wh->uuid, $v->id]), ['action' => 'activate'])->assertSessionHasNoErrors();

        $this->assertEquals($before, DB::table('wms_stock_adjustments')->where('company_id', $this->company->id)->get(['status', 'approval_reasons'])->toArray());
        $this->assertEquals($movements, DB::table('fin_stock_movements')->where('warehouse_id', $this->wh->id)->get()->toArray());
    }
}
