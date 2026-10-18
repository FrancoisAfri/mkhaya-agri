<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\LocationChange;
use App\Modules\Operations\WarehouseManagement\Services\LocationExplorer;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WMS-001 to WMS-005: warehouses, the location hierarchy, location controls and the explorer.
 *
 * The load-bearing tests are the ones that post real stock: a blocked bin is only blocked if the
 * ledger refuses it, whichever road the stock came by.
 */
class WarehouseLayoutTest extends WarehouseTestCase
{
    private function locations(): LocationService
    {
        return app(LocationService::class);
    }

    /** Zone A > Aisle A-01 > Rack A-01-01 > Shelf A-01-01-01 > Bins A-01-01-01-01..02 */
    private function floor(FinWarehouse $wh): array
    {
        return $this->asCompany($this->company, function () use ($wh) {
            $zone = $this->locations()->create($wh, ['code' => 'A', 'level' => 'ZONE']);
            $aisle = $this->locations()->generate($wh, $zone, 1)[0];
            $rack = $this->locations()->generate($wh, $aisle, 1)[0];
            $shelf = $this->locations()->generate($wh, $rack, 1)[0];
            $bins = $this->locations()->generate($wh, $shelf, 2, null, null, ['max_units' => 100]);

            return compact('zone', 'aisle', 'rack', 'shelf', 'bins');
        });
    }

    // ── WMS-002 hierarchy ────────────────────────────────────────────────────────────────────

    public function test_the_hierarchy_runs_zone_to_bin_and_codes_follow_the_parent(): void
    {
        $f = $this->floor($this->warehouse());

        $this->assertSame('A-01', $f['aisle']->code);
        $this->assertSame('AISLE', $f['aisle']->level);
        $this->assertSame('A-01-01-01', $f['shelf']->code);
        $this->assertSame(['A-01-01-01-01', 'A-01-01-01-02'], array_map(fn ($b) => $b->code, $f['bins']));
        $this->assertSame('BIN', $f['bins'][0]->level);
        $this->assertEquals(100, $f['bins'][0]->max_units);
    }

    public function test_running_a_generator_twice_extends_the_run_instead_of_colliding(): void
    {
        $wh = $this->warehouse();
        $f = $this->floor($wh);

        $more = $this->asCompany($this->company, fn () => $this->locations()->generate($wh, $f['shelf'], 2));

        $this->assertSame(['A-01-01-01-03', 'A-01-01-01-04'], array_map(fn ($b) => $b->code, $more));
    }

    public function test_a_location_cannot_sit_inside_a_smaller_one(): void
    {
        $wh = $this->warehouse();
        $f = $this->floor($wh);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot sit inside');

        $this->asCompany($this->company, fn () => $this->locations()->create($wh, [
            'code' => 'Z9', 'level' => 'ZONE', 'parent_id' => $f['bins'][0]->id,
        ]));
    }

    public function test_a_location_cannot_sit_inside_another_warehouse(): void
    {
        $f = $this->floor($this->warehouse('WH1'));
        $other = $this->warehouse('WH2');

        $this->expectExceptionMessage('same warehouse');

        $this->asCompany($this->company, fn () => $this->locations()->create($other, [
            'code' => 'X1', 'level' => 'BIN', 'parent_id' => $f['shelf']->id,
        ]));
    }

    public function test_docks_quarantine_and_in_transit_are_kinds_of_location(): void
    {
        $wh = $this->warehouse();

        $made = $this->asCompany($this->company, fn () => collect(['RECEIVING', 'QUARANTINE', 'RETURNS', 'DISPATCH', 'IN_TRANSIT', 'PRODUCTION', 'STAGING'])
            ->map(fn ($k) => $this->locations()->create($wh, ['code' => $k, 'kind' => $k])));

        $this->assertCount(7, $made);
        $this->assertSame('IN_TRANSIT', $made[4]->kind);
    }

    // ── WMS-004 controls, enforced by the ledger ─────────────────────────────────────────────

    public function test_stock_cannot_be_received_into_a_blocked_bin(): void
    {
        $this->openBooks();
        $wh = $this->warehouse();
        $f = $this->floor($wh);
        $item = $this->stockItem('BOX');

        $this->asCompany($this->company, fn () => $this->locations()->block($f['bins'][0], 'Racking damaged by forklift'));

        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('blocked: Racking damaged by forklift');

        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($item->fresh(), 5, 10, $wh, ['bin_id' => $f['bins'][0]->id]));
    }

    public function test_blocking_an_aisle_blocks_every_bin_inside_it(): void
    {
        $this->openBooks();
        $wh = $this->warehouse();
        $f = $this->floor($wh);
        $item = $this->stockItem('BOX');

        $this->asCompany($this->company, fn () => $this->locations()->block($f['aisle'], 'Pest inspection'));

        try {
            $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($item->fresh(), 5, 10, $wh, ['bin_id' => $f['bins'][1]->id]));
            $this->fail('A bin in a blocked aisle accepted stock.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('A-01 is blocked', $e->getMessage());
            $this->assertStringContainsString('A-01-01-01-02', $e->getMessage());
        }
    }

    public function test_stock_is_held_in_bins_not_in_shelves_or_aisles(): void
    {
        $this->openBooks();
        $wh = $this->warehouse();
        $f = $this->floor($wh);
        $item = $this->stockItem('BOX');

        $this->expectExceptionMessage('not a bin');

        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($item->fresh(), 5, 10, $wh, ['bin_id' => $f['shelf']->id]));
    }

    public function test_a_restriction_with_an_end_lifts_itself(): void
    {
        $wh = $this->warehouse();
        $f = $this->floor($wh);
        $bin = $f['bins'][0];

        $this->asCompany($this->company, fn () => $this->locations()->restrict($bin, 'Floor cleaning', now()->addHour()));
        $this->assertNotNull($bin->fresh()->refusalForIncoming());

        $this->travel(2)->hours();
        $this->assertNull($bin->fresh()->refusalForIncoming(), 'An expired restriction still refused stock.');
    }

    public function test_a_reopened_bin_accepts_stock_and_a_reversal_is_never_blocked(): void
    {
        $this->openBooks();
        $wh = $this->warehouse();
        $f = $this->floor($wh);
        $bin = $f['bins'][0];
        $item = $this->stockItem('BOX');
        $ledger = app(StockLedger::class);

        $this->asCompany($this->company, function () use ($ledger, $item, $wh, $bin) {
            $this->locations()->block($bin, 'Spill');
            $this->locations()->reopen($bin, 'Cleaned and inspected');
            $ledger->receive($item->fresh(), 10, 10, $wh, ['bin_id' => $bin->id]);
            $out = $ledger->adjust($item->fresh(), $wh, -4, 10, ['bin_id' => $bin->id, 'reason' => 'Count', 'source_type' => 'test', 'source_id' => 1]);

            // Blocked after the mistake: correcting it must still be possible.
            $this->locations()->block($bin, 'Beam inspection');
            $ledger->reverse($out, 'Count was wrong');
        });

        $this->assertEquals(10, (float) DB::table('fin_stock_positions')->where('bin_id', $bin->id)->sum('qty'));
    }

    public function test_blocking_needs_a_reason(): void
    {
        $f = $this->floor($this->warehouse());

        $this->expectExceptionMessage('Say why');

        $this->asCompany($this->company, fn () => $this->locations()->block($f['bins'][0], '  '));
    }

    public function test_a_location_holding_stock_cannot_be_retired(): void
    {
        $this->openBooks();
        $wh = $this->warehouse();
        $f = $this->floor($wh);
        $item = $this->stockItem('BOX');
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($item->fresh(), 3, 10, $wh, ['bin_id' => $f['bins'][0]->id]));

        $this->expectExceptionMessage('still holds stock');

        $this->asCompany($this->company, fn () => $this->locations()->deactivate($f['bins'][0]));
    }

    public function test_a_barcode_resolves_to_one_location_in_the_company(): void
    {
        $wh = $this->warehouse();
        $bin = $this->asCompany($this->company, fn () => $this->locations()->create($wh, ['code' => 'B1']));
        $this->assertSame('WH1.B1', $bin->barcode);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(fn () => $this->asCompany($this->company, fn () => $this->locations()->create($this->warehouse('WH2'), ['code' => 'B9', 'barcode' => 'WH1.B1'])));
    }

    public function test_every_change_is_recorded_and_the_record_cannot_be_edited(): void
    {
        $wh = $this->warehouse();
        $f = $this->floor($wh);

        $this->actingAs($this->manager);
        $this->asCompany($this->company, function () use ($f) {
            $this->locations()->update($f['bins'][0], ['max_units' => 250]);
            $this->locations()->block($f['bins'][0], 'Overloaded');
        });

        $changes = LocationChange::withoutGlobalScopes()->where('bin_id', $f['bins'][0]->id)->orderBy('id')->get();
        $this->assertSame(['CREATED', 'UPDATED', 'BLOCKED'], $changes->pluck('action')->all());
        $this->assertEquals([100, 250], $changes[1]->changes['max_units']);
        $this->assertSame($this->manager->id, $changes[2]->actor_id);
        $this->assertSame('Overloaded', $changes[2]->reason);

        DB::statement('SAVEPOINT history_edit');
        try {
            DB::table('wms_location_changes')->where('id', $changes[2]->id)->update(['reason' => 'nothing to see']);
            $this->fail('The location history accepted an edit.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT history_edit');
        }
    }

    // ── WMS-003 warehouse configuration ──────────────────────────────────────────────────────

    public function test_a_warehouse_is_configured_through_the_screen_with_its_policies(): void
    {
        $this->actingAs($this->manager)
            ->post(route('warehouse.layout.warehouses.store'), [
                'code' => 'DC1', 'name' => 'Midrand DC', 'warehouse_type' => 'DISTRIBUTION', 'timezone' => 'Africa/Johannesburg',
                'opening_time' => '06:00', 'closing_time' => '22:00', 'dispatch_cutoff_time' => '16:30',
                'capacity_basis' => 'PALLETS', 'capacity_value' => 1200,
                'policies' => ['rotation' => 'FIFO', 'scan_to_confirm' => false],
                'is_wms_managed' => true,
            ])->assertSessionHasNoErrors()->assertRedirect();

        $wh = FinWarehouse::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'DC1')->firstOrFail();
        $this->assertSame('DISTRIBUTION', $wh->warehouse_type);
        $this->assertSame('FIFO', $wh->policies['rotation']);
        $this->assertFalse($wh->policies['scan_to_confirm']);
        // Unset policies take the defaults rather than being missing.
        $this->assertSame('DISCRETE', $wh->policies['picking']);
        $this->assertTrue($wh->is_wms_managed);
        $this->assertStringStartsWith('16:30', $wh->dispatch_cutoff_time);
        $this->assertNotNull($wh->entity_id, 'A warehouse must belong to one of the company\'s businesses.');
        $this->assertTrue(LocationChange::withoutGlobalScopes()->where('warehouse_id', $wh->id)->where('action', 'CREATED')->exists());
    }

    public function test_a_linked_site_syncs_its_timezone_only_while_sync_is_on(): void
    {
        $siteId = DB::table('sheq_sites')->insertGetId([
            'company_id' => $this->company->id, 'code' => 'WDH', 'name' => 'Windhoek Yard', 'timezone' => 'Africa/Windhoek',
        ]);
        $wh = $this->warehouse();

        $this->asCompany($this->company, fn () => app(WarehouseDirectory::class)->save(['sheq_site_id' => $siteId, 'sync_from_site' => false], $wh));
        $this->assertSame('Africa/Johannesburg', $wh->fresh()->timezone, 'A link with sync off must not change the warehouse.');

        $this->asCompany($this->company, fn () => app(WarehouseDirectory::class)->save(['sync_from_site' => true], $wh->fresh()));
        $this->assertSame('Africa/Windhoek', $wh->fresh()->timezone);
        $this->assertNotNull($wh->fresh()->site_synced_at);

        DB::table('sheq_sites')->where('id', $siteId)->update(['timezone' => 'Africa/Gaborone']);
        $this->actingAs($this->manager)->post(route('warehouse.layout.warehouses.sync', $wh->uuid))->assertSessionHasNoErrors();
        $this->assertSame('Africa/Gaborone', $wh->fresh()->timezone);
    }

    public function test_only_one_warehouse_is_the_default(): void
    {
        $a = $this->warehouse('A', ['is_default' => true]);
        $b = $this->warehouse('B');

        $this->asCompany($this->company, fn () => app(WarehouseDirectory::class)->save(['is_default' => true], $b));

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
    }

    public function test_a_warehouse_holding_stock_cannot_be_retired(): void
    {
        $this->openBooks();
        $wh = $this->warehouse();
        $item = $this->stockItem('BOX');
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($item->fresh(), 1, 10, $wh));

        $this->actingAs($this->manager)->delete(route('warehouse.layout.warehouses.destroy', $wh->uuid))
            ->assertSessionHasErrors('refused');
        $this->assertTrue($wh->fresh()->is_active);
    }

    public function test_assigned_staff_limit_who_may_do_what(): void
    {
        $wh = $this->warehouse();
        $dir = app(WarehouseDirectory::class);

        // Nobody assigned: open to anybody with access.
        $this->assertTrue($dir->permits($wh, $this->viewer->id, 'PICK'));

        $this->actingAs($this->manager)->post(route('warehouse.layout.warehouses.staff.store', $wh->uuid), [
            'user_id' => $this->viewer->id, 'activities' => ['PICK', 'PACK'],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($dir->permits($wh, $this->viewer->id, 'PICK'));
        $this->assertFalse($dir->permits($wh, $this->viewer->id, 'RECEIVE'));
        $this->assertFalse($dir->permits($wh, $this->supervisor->id, 'PICK'), 'An unassigned person kept access once staff were assigned.');
    }

    public function test_a_person_from_another_company_cannot_be_assigned(): void
    {
        $wh = $this->warehouse();
        $foreign = \App\Core\User\Models\User::factory()->create([
            'email' => 'foreign-'.Str::random(5).'@example.test', 'company_id' => Company::create(['name' => 'Elsewhere'])->id,
        ]);

        $this->actingAs($this->manager)->post(route('warehouse.layout.warehouses.staff.store', $wh->uuid), [
            'user_id' => $foreign->id, 'activities' => ['PICK'],
        ])->assertSessionHasErrors('user_id');
    }

    // ── WMS-001 company boundaries and permissions ───────────────────────────────────────────

    public function test_another_companys_warehouses_are_invisible_and_untouchable(): void
    {
        $other = Company::create(['name' => 'Rival Logistics']);
        $theirs = $this->asCompany($other, fn () => FinWarehouse::create(['company_id' => $other->id, 'code' => 'RIV', 'name' => 'Rival DC', 'is_active' => true]));
        $this->warehouse('MINE');

        $this->actingAs($this->manager)->get(route('warehouse.layout.warehouses.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Modules/Warehouse/Layout/Warehouses')
                ->where('warehouses', fn ($list) => collect($list)->pluck('code')->all() === ['MINE']));

        $this->actingAs($this->manager)->patch(route('warehouse.layout.warehouses.update', $theirs->uuid), [
            'code' => 'RIV', 'name' => 'Hijacked', 'warehouse_type' => 'GENERAL', 'timezone' => 'UTC',
        ])->assertNotFound();
        $this->assertSame('Rival DC', $theirs->fresh()->name);
    }

    public function test_a_supervisor_may_block_a_bin_but_not_redesign_the_warehouse(): void
    {
        $wh = $this->warehouse();
        $f = $this->floor($wh);

        $this->actingAs($this->supervisor)
            ->post(route('warehouse.layout.locations.status', [$wh->uuid, $f['bins'][0]->uuid]), ['status' => 'BLOCKED', 'reason' => 'Leak'])
            ->assertSessionHasNoErrors();
        $this->assertSame('BLOCKED', $f['bins'][0]->fresh()->status);

        $this->actingAs($this->supervisor)
            ->post(route('warehouse.layout.locations.store', $wh->uuid), ['code' => 'NEW', 'level' => 'BIN', 'kind' => 'STORAGE'])
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->post(route('warehouse.layout.locations.status', [$wh->uuid, $f['bins'][1]->uuid]), ['status' => 'BLOCKED', 'reason' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->outsider)->get(route('warehouse.layout.locations.index'))->assertForbidden();
    }

    // ── WMS-005 explorer and WMS-042 occupancy ───────────────────────────────────────────────

    public function test_the_explorer_rolls_stock_up_the_hierarchy_and_warns_when_a_bin_is_nearly_full(): void
    {
        $this->openBooks();
        $wh = $this->warehouse();
        $f = $this->floor($wh);
        $item = $this->stockItem('BOX');
        $this->asCompany($this->company, function () use ($item, $wh, $f) {
            app(StockLedger::class)->receive($item->fresh(), 95, 10, $wh, ['bin_id' => $f['bins'][0]->id]);
            app(StockLedger::class)->receive($item->fresh(), 20, 10, $wh, ['bin_id' => $f['bins'][1]->id]);
        });

        $tree = $this->asCompany($this->company, fn () => app(LocationExplorer::class)->tree($wh));
        $rows = collect($tree['rows'])->keyBy('code');

        $this->assertEquals(115, $rows['A']['qty'], 'The zone does not show the stock in its bins.');
        $this->assertSame(2, $rows['A-01']['bins_with_stock']);
        $this->assertEqualsWithDelta(0.95, $rows['A-01-01-01-01']['occupancy'], 1e-6);
        $this->assertSame('NEAR_FULL', $rows['A-01-01-01-01']['warning']);
        $this->assertNull($rows['A-01-01-01-02']['warning']);
        $this->assertSame(1, $tree['totals']['bins_near_full']);
        // Depth-first: a parent is listed before its children.
        $this->assertSame(['A', 'A-01', 'A-01-01', 'A-01-01-01'], array_slice(array_column($tree['rows'], 'code'), 0, 4));
    }

    // ── Every screen renders, and the hub links to them ──────────────────────────────────────

    public function test_every_screen_renders(): void
    {
        $wh = $this->warehouse();
        $this->floor($wh);
        $this->actingAs($this->manager);

        $this->get(route('warehouse.dashboard'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Dashboard')->has('queues', 12));
        $this->get(route('warehouse.layout.warehouses.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Layout/Warehouses'));
        $this->get(route('warehouse.layout.locations.index', ['warehouse' => $wh->uuid]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Layout/Locations')->has('explorer.rows', 6));
        $this->get(route('warehouse.layout.labels', ['warehouse' => $wh->uuid, 'level' => 'BIN']))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Layout/Labels')->has('labels', 2)
                ->where('labels.0.qr', fn ($svg) => str_contains($svg, '<svg')));
        $this->get(route('warehouse.layout.history', ['warehouse' => $wh->uuid]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Layout/History'));
        $this->get(route('warehouse.hub.layout'))->assertOk();
    }

    public function test_every_page_component_exists(): void
    {
        foreach (['Dashboard', 'Layout/Warehouses', 'Layout/Locations', 'Layout/Labels', 'Layout/History'] as $page) {
            $this->assertFileExists(resource_path("js/Modules/Warehouse/{$page}.vue"));
        }
    }
}
