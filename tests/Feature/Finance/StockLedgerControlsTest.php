<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinStockBalance;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MFG-008 -- the inventory foundation, one test per mandatory control.
 *
 *  1. Available stock excludes reserved, quarantined, expired or otherwise unavailable stock.
 *  2. Material issues reference a production order or authorised adjustment.
 *  3. Finished-goods receipts reference the production order.
 *  4. Every movement has a traceable reference.
 *  5. Movements are atomic and safe against duplicate requests.
 *  6. Corrections use reversal, never edits.
 *  7. No second independent balance: positions always add up to the balance.
 */
class StockLedgerControlsTest extends TestCase
{
    use DatabaseTransactions;

    private StockLedger $ledger;

    private FinWarehouse $main;

    private FinWarehouse $line;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1310')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }

        $this->ledger = app(StockLedger::class);
        $this->main = FinWarehouse::create(['code' => 'MAIN'.uniqid(), 'name' => 'Main', 'is_active' => true]);
        $this->line = FinWarehouse::create(['code' => 'LINE'.uniqid(), 'name' => 'Line side', 'is_active' => true]);
    }

    private function item(string $method = 'weighted_avg'): FinStockItem
    {
        return app(InventoryService::class)->createItem([
            'code' => 'RM'.uniqid(), 'name' => 'Resin', 'cost_method' => $method,
        ]);
    }

    private function stock(FinStockItem $item, float $qty, array $options = [], ?FinWarehouse $wh = null): FinStockMovement
    {
        return $this->ledger->receive($item, $qty, 10.0, $wh ?? $this->main, $options);
    }

    private function order(int $id = 1): array
    {
        return ['source_type' => 'PRODUCTION_ORDER', 'source_id' => $id];
    }

    /** Control 7, checked after every scenario: the breakdown always equals the balance. */
    private function assertBreakdownMatchesBalance(FinStockItem $item): void
    {
        foreach (FinStockBalance::withoutGlobalScopes()->where('item_id', $item->id)->get() as $balance) {
            $sum = (float) FinStockPosition::withoutGlobalScopes()
                ->where('item_id', $item->id)->where('warehouse_id', $balance->warehouse_id)->sum('qty');

            $this->assertEqualsWithDelta((float) $balance->qty, $sum, 0.0001,
                "Positions in warehouse {$balance->warehouse_id} drifted from the balance.");
        }
    }

    private function accountCodesOf(FinStockMovement $m): array
    {
        return FinJournalLine::where('journal_id', $m->journal_id)
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->get(['fin_accounts.code', 'fin_journal_lines.debit', 'fin_journal_lines.credit'])
            ->mapWithKeys(fn ($l) => [$l->code => (float) $l->debit > 0 ? 'Dr' : 'Cr'])->all();
    }

    // ── 1. availability ──────────────────────────────────────────────────────────────────────

    public function test_quarantined_stock_is_not_available_until_released(): void
    {
        $item = $this->item();
        $this->stock($item, 100, ['status' => 'QUARANTINE']);

        $this->assertSame(0.0, $this->ledger->available($item, $this->main->id));

        $this->ledger->changeStatus($item, $this->main, 60, 'QUARANTINE', 'AVAILABLE', ['reason' => 'Passed inspection']);

        $this->assertSame(60.0, $this->ledger->available($item, $this->main->id));
        $this->assertBreakdownMatchesBalance($item);
    }

    public function test_quarantined_stock_cannot_be_issued(): void
    {
        $item = $this->item();
        $this->stock($item, 50, ['status' => 'QUARANTINE']);

        $this->expectException(PostingException::class);
        $this->ledger->issueToProduction($item, 10, $this->main, $this->order());
    }

    public function test_an_expired_lot_is_not_available_and_cannot_be_issued(): void
    {
        $item = $this->item();
        $old = $this->ledger->lot($item, 'L-OLD', ['expires_on' => now()->subDay()]);
        $fresh = $this->ledger->lot($item, 'L-NEW', ['expires_on' => now()->addMonth()]);

        $this->stock($item, 30, ['lot_id' => $old->id]);
        $this->stock($item, 20, ['lot_id' => $fresh->id]);

        $this->assertSame(20.0, $this->ledger->available($item, $this->main->id));

        try {
            $this->ledger->issueToProduction($item, 5, $this->main, $this->order() + ['lot_id' => $old->id]);
            $this->fail('An expired lot was issued to production.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
    }

    public function test_stock_reserved_for_one_order_cannot_be_taken_by_another(): void
    {
        $item = $this->item();
        $this->stock($item, 100);

        $this->ledger->reserve($item, $this->main, 80, 'PRODUCTION_ORDER', 1);

        $this->assertSame(20.0, $this->ledger->available($item, $this->main->id));

        try {
            $this->ledger->issueToProduction($item, 30, $this->main, $this->order(2));
            $this->fail('Order 2 took stock reserved for order 1.');
        } catch (PostingException) {
        }

        // Order 1 may take what is held for it, and its reservation is drawn down.
        $this->ledger->issueToProduction($item, 50, $this->main, $this->order(1));
        $reservation = FinStockReservation::withoutGlobalScopes()->where('source_id', 1)->firstOrFail();

        $this->assertSame(30.0, $reservation->remaining());
        $this->assertSame(FinStockReservation::OPEN, $reservation->status);
    }

    public function test_reserving_again_for_the_same_order_tops_up_rather_than_doubling(): void
    {
        $item = $this->item();
        $this->stock($item, 100);

        $this->ledger->reserve($item, $this->main, 40, 'PRODUCTION_ORDER', 7);
        $this->ledger->reserve($item, $this->main, 60, 'PRODUCTION_ORDER', 7);

        $this->assertSame(1, FinStockReservation::withoutGlobalScopes()->where('source_id', 7)->count());
        $this->assertSame(40.0, $this->ledger->available($item, $this->main->id), 'Reservations were double-counted.');
    }

    public function test_cannot_reserve_more_than_is_free(): void
    {
        $item = $this->item();
        $this->stock($item, 10);

        $this->expectException(PostingException::class);
        $this->ledger->reserve($item, $this->main, 11, 'PRODUCTION_ORDER', 1);
    }

    public function test_releasing_a_cancelled_orders_reservations_frees_the_stock(): void
    {
        $item = $this->item();
        $this->stock($item, 100);
        $this->ledger->reserve($item, $this->main, 100, 'PRODUCTION_ORDER', 9);

        $this->assertSame(1, $this->ledger->releaseFor('PRODUCTION_ORDER', 9, 'Order cancelled'));
        $this->assertSame(100.0, $this->ledger->available($item, $this->main->id));
    }

    public function test_picking_is_first_expiring_first_and_skips_unusable_stock(): void
    {
        $item = $this->item();
        $late = $this->ledger->lot($item, 'LATE', ['expires_on' => now()->addMonths(6)]);
        $soon = $this->ledger->lot($item, 'SOON', ['expires_on' => now()->addDays(5)]);
        $gone = $this->ledger->lot($item, 'GONE', ['expires_on' => now()->subDays(2)]);
        $held = $this->ledger->lot($item, 'HELD', ['expires_on' => now()->addDays(1)]);

        $this->stock($item, 10, ['lot_id' => $late->id]);
        $this->stock($item, 10, ['lot_id' => $soon->id]);
        $this->stock($item, 10, ['lot_id' => $gone->id]);
        $this->stock($item, 10, ['lot_id' => $held->id, 'status' => 'QUARANTINE']);

        $picks = $this->ledger->pick($item, $this->main->id, 15);

        $this->assertSame([[$soon->id, 10.0], [$late->id, 5.0]], array_map(fn ($p) => [$p['lot_id'], $p['qty']], $picks));
    }

    public function test_a_pick_that_cannot_be_covered_says_so_rather_than_coming_up_short(): void
    {
        $item = $this->item();
        $this->stock($item, 5);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('Only 5');
        $this->ledger->pick($item, $this->main->id, 6);
    }

    // ── 2 & 3. source documents ─────────────────────────────────────────────────────────────

    public function test_a_material_issue_must_name_its_production_order(): void
    {
        $item = $this->item();
        $this->stock($item, 10);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('production order');
        $this->ledger->issueToProduction($item, 1, $this->main, []);
    }

    public function test_a_finished_goods_receipt_must_name_its_production_order(): void
    {
        $this->expectException(PostingException::class);
        $this->ledger->receiveFromProduction($this->item(), 1, 5, $this->main, []);
    }

    public function test_material_to_production_is_work_in_progress_not_cost_of_sales(): void
    {
        $item = $this->item();
        $this->stock($item, 10);

        $issue = $this->ledger->issueToProduction($item, 4, $this->main, $this->order());

        $this->assertEquals(['1310' => 'Dr', '1300' => 'Cr'], $this->accountCodesOf($issue));
    }

    public function test_finished_goods_relieve_work_in_progress(): void
    {
        $receipt = $this->ledger->receiveFromProduction($this->item(), 5, 40, $this->main, $this->order());

        $this->assertEquals(['1300' => 'Dr', '1310' => 'Cr'], $this->accountCodesOf($receipt));
    }

    public function test_scrap_is_written_off_to_scrap_and_spoilage(): void
    {
        $item = $this->item();
        $this->stock($item, 10, ['status' => 'REJECTED']);

        $scrap = $this->ledger->scrap($item, 10, $this->main, $this->order() + ['status' => 'REJECTED']);

        $this->assertEquals(['5610' => 'Dr', '1300' => 'Cr'], $this->accountCodesOf($scrap));
        $this->assertBreakdownMatchesBalance($item);
    }

    public function test_unused_material_returns_from_production_against_work_in_progress(): void
    {
        $item = $this->item();
        $this->stock($item, 10);
        $this->ledger->issueToProduction($item, 6, $this->main, $this->order());

        $back = $this->ledger->returnFromProduction($item, 2, 10, $this->main, $this->order());

        $this->assertEquals(['1300' => 'Dr', '1310' => 'Cr'], $this->accountCodesOf($back));
        $this->assertSame(6.0, $this->ledger->available($item, $this->main->id));
    }

    // ── 4. traceability ─────────────────────────────────────────────────────────────────────

    public function test_every_movement_records_where_which_lot_and_why(): void
    {
        $item = $this->item();
        $bin = FinStockBin::create(['warehouse_id' => $this->main->id, 'code' => 'A-01', 'kind' => 'STORAGE']);
        $lot = $this->ledger->lot($item, 'TRACE-1');

        $this->stock($item, 20, ['lot_id' => $lot->id, 'bin_id' => $bin->id, 'reference' => 'GRN-77']);
        $issue = $this->ledger->issueToProduction($item, 5, $this->main, $this->order(42) + [
            'lot_id' => $lot->id, 'bin_id' => $bin->id, 'reference' => 'PO-2026-00042',
        ]);

        $row = FinStockMovement::withoutGlobalScopes()->find($issue->id);

        // warehouse_id used to be silently dropped by mass assignment on every movement.
        $this->assertSame($this->main->id, (int) $row->warehouse_id, 'The movement lost its warehouse.');
        $this->assertSame($lot->id, (int) $row->lot_id);
        $this->assertSame($bin->id, (int) $row->bin_id);
        $this->assertSame('PRODUCTION_ORDER', $row->source_type);
        $this->assertSame(42, (int) $row->source_id);
        $this->assertNotNull($row->uuid);
        $this->assertNotNull($row->journal_id);
    }

    // ── 5. atomic and duplicate-safe ────────────────────────────────────────────────────────

    public function test_a_retried_request_posts_once(): void
    {
        $item = $this->item();
        $this->stock($item, 100);

        $first = $this->ledger->issueToProduction($item, 10, $this->main, $this->order() + ['idempotency_key' => 'scan-abc-1']);
        $again = $this->ledger->issueToProduction($item, 10, $this->main, $this->order() + ['idempotency_key' => 'scan-abc-1']);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, FinStockMovement::withoutGlobalScopes()->where('idempotency_key', 'scan-abc-1')->count());
        $this->assertSame(90.0, (float) $item->fresh()->qty_on_hand, 'The retry issued the material twice.');
    }

    public function test_a_failed_movement_leaves_nothing_behind(): void
    {
        $item = $this->item();
        $this->stock($item, 5);
        $movementsBefore = FinStockMovement::withoutGlobalScopes()->where('item_id', $item->id)->count();

        try {
            $this->ledger->issueToProduction($item, 50, $this->main, $this->order());
        } catch (PostingException) {
        }

        $this->assertSame($movementsBefore, FinStockMovement::withoutGlobalScopes()->where('item_id', $item->id)->count());
        $this->assertSame(5.0, (float) $item->fresh()->qty_on_hand);
        $this->assertBreakdownMatchesBalance($item);
    }

    // ── 6. corrections by reversal ──────────────────────────────────────────────────────────

    public function test_a_posted_movement_cannot_be_edited_or_deleted(): void
    {
        $item = $this->item();
        $m = $this->stock($item, 10);

        foreach ([
            fn () => DB::table('fin_stock_movements')->where('id', $m->id)->update(['qty' => 99]),
            fn () => DB::table('fin_stock_movements')->where('id', $m->id)->delete(),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('A posted stock movement was changed in place.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('reverse it instead', $e->getMessage());
            }
        }
    }

    public function test_reversing_a_receipt_takes_the_stock_back_out_with_a_mirrored_journal(): void
    {
        $item = $this->item();
        $receipt = $this->stock($item, 25);

        $reversal = $this->ledger->reverse($receipt, 'Received against the wrong order');

        $this->assertSame($receipt->id, (int) $reversal->reversal_of_id);
        $this->assertSame(0.0, (float) $item->fresh()->qty_on_hand);
        $this->assertEquals(['1300' => 'Cr', '2100' => 'Dr'], $this->accountCodesOf($reversal));
        $this->assertBreakdownMatchesBalance($item);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('already been reversed');
        $this->ledger->reverse($receipt, 'Again');
    }

    public function test_reversing_an_issue_puts_the_material_back_where_it_came_from(): void
    {
        $item = $this->item('fifo');
        $lot = $this->ledger->lot($item, 'REV-LOT');
        $this->stock($item, 10, ['lot_id' => $lot->id]);
        $issue = $this->ledger->issueToProduction($item, 4, $this->main, $this->order() + ['lot_id' => $lot->id]);

        $this->ledger->reverse($issue, 'Issued in error');

        $this->assertSame(10.0, $this->ledger->available($item, $this->main->id, $lot->id));
        $this->assertBreakdownMatchesBalance($item);
    }

    // ── 7 and the rest ──────────────────────────────────────────────────────────────────────

    public function test_positions_add_up_to_the_balance_through_a_busy_day(): void
    {
        $item = $this->item('fifo');
        $lotA = $this->ledger->lot($item, 'A');
        $lotB = $this->ledger->lot($item, 'B');
        $bin = FinStockBin::create(['warehouse_id' => $this->line->id, 'code' => 'LS-1']);

        $this->stock($item, 40, ['lot_id' => $lotA->id]);
        $this->stock($item, 30, ['lot_id' => $lotB->id, 'status' => 'QUARANTINE']);
        $this->ledger->changeStatus($item, $this->main, 30, 'QUARANTINE', 'AVAILABLE', ['lot_id' => $lotB->id]);
        $this->ledger->transfer($item, $this->main, $this->line, 15, ['lot_id' => $lotA->id, 'to_bin_id' => $bin->id]);
        $this->ledger->issueToProduction($item, 10, $this->line, $this->order() + ['lot_id' => $lotA->id, 'bin_id' => $bin->id]);
        $this->ledger->changeStatus($item, $this->main, 5, 'AVAILABLE', 'REJECTED', ['lot_id' => $lotB->id]);
        $this->ledger->scrap($item, 5, $this->main, $this->order() + ['lot_id' => $lotB->id, 'status' => 'REJECTED']);
        app(InventoryService::class)->sell($item, 3, null, 'legacy invoice', $this->main);   // a caller with no context

        $this->assertBreakdownMatchesBalance($item);
        $this->assertSame(5.0, $this->ledger->available($item, $this->line->id, $lotA->id));
    }

    public function test_a_transfer_between_bins_keeps_the_lot(): void
    {
        $item = $this->item();
        $lot = $this->ledger->lot($item, 'KEEP');
        $a = FinStockBin::create(['warehouse_id' => $this->main->id, 'code' => 'A']);
        $b = FinStockBin::create(['warehouse_id' => $this->main->id, 'code' => 'B']);

        $this->stock($item, 10, ['lot_id' => $lot->id, 'bin_id' => $a->id]);
        $this->ledger->transfer($item, $this->main, $this->main, 4, ['lot_id' => $lot->id, 'bin_id' => $a->id, 'to_bin_id' => $b->id]);

        $this->assertSame(4.0, $this->ledger->available($item, $this->main->id, $lot->id, $b->id));
        $this->assertSame(6.0, $this->ledger->available($item, $this->main->id, $lot->id, $a->id));
    }

    public function test_a_cycle_count_posts_only_the_difference_and_an_adjustment_needs_a_reason(): void
    {
        $item = $this->item();
        $this->stock($item, 50);

        $this->assertNull($this->ledger->count($item, $this->main, 50, []), 'A count that agrees posted something.');

        $m = $this->ledger->count($item, $this->main, 47, ['reason' => 'Monthly count, aisle 3']);
        $this->assertSame(-3.0, (float) $m->qty);
        $this->assertSame('CYCLE_COUNT', $m->source_type);

        $this->expectException(PostingException::class);
        $this->ledger->adjust($item, $this->main, -1, 10, []);
    }

    public function test_a_lot_number_is_one_lot_per_item(): void
    {
        $item = $this->item();

        $this->assertSame($this->ledger->lot($item, 'SAME')->id, $this->ledger->lot($item, ' SAME ')->id);
    }
}
