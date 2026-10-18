<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Console\RetentionCommand;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Models\WarehouseStaff;
use App\Modules\Operations\WarehouseManagement\Services\EvidenceLocker;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseOverrides;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Operations\WarehouseManagement\Support\RolePresets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** WMS-200..203, 220..221: warehouse access, emergency override, the audit ledger, retention, account and upload security. */
class GovernanceTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('GV', ['is_wms_managed' => true]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            ReasonCode::query()->where('code', 'DAMAGED')->update(['always_requires_approval' => true]);
            $this->bin = app(LocationService::class)->create($this->wh, ['code' => 'G1']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'BOX', 'name' => 'Box', 'uom' => 'ea', 'standard_cost' => 50]);
            app(StockLedger::class)->receive($this->item->fresh(), 10, 50, $this->wh, ['bin_id' => $this->bin->id]);
        });
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    public function test_a_warehouse_restricted_to_its_staff_refuses_and_hides_itself_from_others(): void
    {
        $picker = $this->person('picker', [RoleCatalog::VIEW, RoleCatalog::MOVE_STOCK]);
        $other = $this->warehouse('OPEN', ['is_wms_managed' => true]);
        $this->in(fn () => $this->wh->update(['policies' => array_merge($this->wh->policies ?? [], ['restrict_to_staff' => true])]));
        $move = fn () => $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 1, 50, $this->wh->fresh(), ['bin_id' => $this->bin->id]));

        $this->actingAs($picker);
        try {
            $move();
            $this->fail('A person not assigned to the warehouse moved its stock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not assigned to warehouse GV', $e->getMessage());
        }
        $this->actingAs($picker)->get(route('warehouse.dashboard'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('warehouses', fn ($w) => collect($w)->pluck('code')->all() === ['OPEN']));
        $this->actingAs($picker)->get(route('warehouse.insights.dashboard', ['inventory', 'warehouse_id' => $this->wh->id]))->assertForbidden();

        $this->in(fn () => WarehouseStaff::create(['company_id' => $this->company->id, 'warehouse_id' => $this->wh->id, 'user_id' => $picker->id, 'activities' => ['PICK'], 'is_active' => true]));
        $this->actingAs($picker);
        $move();
        $this->assertSame(11.0, (float) DB::table('fin_stock_positions')->where('item_id', $this->item->id)->where('warehouse_id', $this->wh->id)->sum('qty'));
        $this->assertTrue(DB::table('wms_audit_events')->where('company_id', $this->company->id)->where('action', 'warehouse_staff.created')->exists(), 'staff changes are audited');
        $this->assertTrue(DB::table('wms_audit_events')->where('action', 'warehouse.changed')->where('entity_ref', 'GV')->exists(), 'policy changes are audited');
    }

    public function test_the_emergency_override_lets_a_person_approve_their_own_request_only_inside_it_and_is_reviewed_by_another(): void
    {
        $lead = $this->person('lead', [RoleCatalog::VIEW, RoleCatalog::ADJUST_STOCK, RoleCatalog::APPROVE_ADJUSTMENTS, RoleCatalog::EMERGENCY_OVERRIDE]);
        $reason = $this->in(fn () => ReasonCode::query()->where('code', 'DAMAGED')->firstOrFail());
        $adj = $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id, 'bin_id' => $this->bin->id, 'qty' => -1, 'reason_code_id' => $reason->id], $lead));

        $this->actingAs($lead)->post(route('warehouse.stock.adjustments.decide', $adj->uuid), ['decision' => 'approve'])->assertSessionHasErrors('refused');
        $this->actingAs($lead)->post(route('warehouse.governance.override'), ['reason' => 'Night shift alone, damaged pallet blocking the dock door', 'types' => ['ADJUSTMENT'], 'minutes' => 300])
            ->assertSessionHasErrors('minutes');
        $this->actingAs($lead)->post(route('warehouse.governance.override'), ['reason' => 'Night shift alone, damaged pallet blocking the dock door', 'types' => ['ADJUSTMENT'], 'minutes' => 60])
            ->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('wms_notification_log')->where('recipient_id', $this->manager->id)->where('event_type', 'OVERRIDE')->exists(), 'managers are told at once');

        $this->actingAs($lead)->post(route('warehouse.stock.adjustments.decide', $adj->uuid), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(StockAdjustment::POSTED, $adj->fresh()->status);
        $o = DB::table('wms_overrides')->where('user_id', $lead->id)->sole();
        $this->assertSame(1, (int) $o->uses);
        $this->assertTrue(DB::table('wms_audit_events')->where('action', 'override.used')->where('entity_ref', $adj->number)->exists());

        $this->actingAs($lead)->post(route('warehouse.governance.override.act', $o->id), ['action' => 'review', 'note' => 'Reviewed my own use'])->assertSessionHasErrors('refused');
        $this->actingAs($this->manager)->post(route('warehouse.governance.override.act', $o->id), ['action' => 'review', 'note' => 'Photos confirm the damage; fine.'])->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('wms_overrides')->where('id', $o->id)->value('reviewed_at'));
        $this->in(fn () => app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseNotifier::class)->run($this->company->id));
        $this->assertSame(0, DB::table('wms_notification_log')->where('event_type', 'OVERRIDE')->whereNull('resolved_at')->count(), 'the alert closes once reviewed');

        // Outside the window, the old rule is back.
        $this->travel(2)->hours();
        $this->assertNull(app(WarehouseOverrides::class)->active($lead, 'ADJUSTMENT'));
    }

    public function test_the_audit_ledger_is_chained_append_only_and_shows_tampering(): void
    {
        $audit = app(WarehouseAudit::class);
        $this->actingAs($this->manager);
        foreach (['one', 'two', 'three'] as $s) {
            $audit->record($this->company->id, 'test.event', 'TEST', $s, "Event {$s}", ['a' => 1, 'z' => ['y' => 2, 'b' => 3]], ['password' => 'hunter2']);
        }
        $this->assertTrue($audit->verify($this->company->id)['ok']);
        $this->assertSame('[redacted]', json_decode(DB::table('wms_audit_events')->where('entity_ref', 'one')->value('after'), true)['password']);

        foreach (["UPDATE wms_audit_events SET summary = 'x' WHERE entity_ref = 'two'", "DELETE FROM wms_audit_events WHERE entity_ref = 'two'"] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("{$sql} was allowed");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        // Somebody with the power to switch the trigger off still leaves a trace in the chain.
        DB::statement('ALTER TABLE wms_audit_events DISABLE TRIGGER wms_audit_append_only');
        DB::table('wms_audit_events')->where('entity_ref', 'two')->update(['summary' => 'Event rewritten']);
        DB::statement('ALTER TABLE wms_audit_events ENABLE TRIGGER wms_audit_append_only');
        $r = $audit->verify($this->company->id);
        $this->assertFalse($r['ok']);
        $this->assertSame((int) DB::table('wms_audit_events')->where('entity_ref', 'two')->value('sequence'), $r['broken_at']);

        $this->actingAs($this->viewer)->get(route('warehouse.governance.index'))->assertForbidden();
        $auditor = $this->person('auditor', RolePresets::all()['Auditor']);
        $this->actingAs($auditor)->get(route('warehouse.governance.index', ['verify' => 1]))->assertOk()->assertInertia(fn ($p) => $p->where('chain.ok', false));
        $this->actingAs($auditor)->get(route('warehouse.governance.export'))->assertOk();
    }

    public function test_retention_anonymises_personal_details_keeps_the_stock_and_purges_only_old_audit_events(): void
    {
        $order = DB::table('wms_orders')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id, 'number' => 'FO-OLD', 'warehouse_id' => $this->wh->id,
            'source' => 'ECOMMERCE', 'external_ref' => 'W-1', 'customer_name' => 'Thandi Mokoena', 'ship_to' => json_encode(['name' => 'Thandi', 'phone' => '082', 'city' => 'Durban', 'province' => 'KZN']),
            'status' => 'DELIVERED', 'priority' => 50, 'created_at' => now()->subYears(3), 'updated_at' => now()->subYears(3)]);
        $shipment = DB::table('wms_shipments')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id, 'number' => 'SH-OLD', 'order_id' => $order,
            'warehouse_id' => $this->wh->id, 'status' => 'DELIVERED', 'handover_name' => 'Sipho Dlamini', 'driver_name' => 'Sipho', 'vehicle_reg' => 'ND 123', 'pod_received_by' => 'Thandi',
            'dispatched_at' => now()->subYears(3), 'created_at' => now()->subYears(3), 'updated_at' => now()->subYears(3)]);
        $this->actingAs($this->manager)->post(route('warehouse.governance.settings'), ['audit_retention_days' => 30, 'personal_data_days' => 730, 'export_file_days' => 30,
            'notification_log_days' => 365, 'offline_action_days' => 180])->assertSessionHasErrors('refused');

        DB::table('wms_audit_events')->insert(['company_id' => $this->company->id, 'sequence' => 0, 'occurred_at' => now()->subYears(9), 'actor_type' => 'SYSTEM', 'action' => 'ancient',
            'entity_type' => 'TEST', 'summary' => 'old', 'hash' => str_repeat('0', 64), 'created_at' => now()->subYears(9)]);
        $done = app(RetentionCommand::class)->company($this->company->id, app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseSettings::class)->get($this->company->id), false);

        $this->assertSame(1, $done['shipments_anonymised']);
        $s = DB::table('wms_shipments')->where('id', $shipment)->first();
        $this->assertSame([RetentionCommand::REMOVED, RetentionCommand::REMOVED, RetentionCommand::REMOVED, RetentionCommand::REMOVED], [$s->handover_name, $s->driver_name, $s->vehicle_reg, $s->pod_received_by]);
        $o = DB::table('wms_orders')->where('id', $order)->first();
        $this->assertSame(RetentionCommand::REMOVED, $o->customer_name);
        $this->assertSame(['city' => 'Durban', 'province' => 'KZN'], json_decode($o->ship_to, true));
        $this->assertSame(1, $done['audit_events_purged']);
        $this->assertFalse(DB::table('wms_audit_events')->where('action', 'ancient')->exists());
        $this->assertTrue(DB::table('wms_audit_events')->where('company_id', $this->company->id)->exists(), 'recent events stay');
        $this->assertSame(10.0, (float) DB::table('fin_stock_positions')->where('item_id', $this->item->id)->sum('qty'), 'stock history is untouched');
    }

    public function test_a_deactivated_user_is_refused_by_the_api_and_an_infected_upload_is_refused(): void
    {
        $plain = $this->manager->createToken('erp', ['*'])->plainTextToken;
        $this->withToken($plain)->getJson('/api/v1/warehouse/event-types')->assertOk();
        $this->manager->forceFill(['status' => 0])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/warehouse/event-types')->assertForbidden();
        $this->flushHeaders();
        $this->manager->forceFill(['status' => 1])->save();

        $eicar = UploadedFile::fake()->createWithContent('note.txt', 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
        $this->expectExceptionMessage('refused');
        $this->in(fn () => app(EvidenceLocker::class)->attach('ADJUSTMENT', 1, $this->company->id, $eicar, $this->manager, 'DOCUMENT'));
    }
}
