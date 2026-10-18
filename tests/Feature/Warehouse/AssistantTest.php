<?php

namespace Tests\Feature\Warehouse;

use App\Core\AI\Contracts\AIAgentInterface;
use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\TransferOrder;
use App\Modules\Operations\WarehouseManagement\Services\AssistantDesk;
use App\Modules\Operations\WarehouseManagement\Services\Counting;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\ProtectionCases;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAssistant;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseNarrator;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Shared\DTOs\AgentResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** WMS-140..144: grounded answers, permission-aware, drafts only, idempotent, measured. */
class AssistantTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinWarehouse $spare;

    private FinStockItem $item;

    private FinStockBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['warehouse.assistant_narration' => false]);
        $this->openBooks();
        $this->wh = $this->warehouse('MAIN', ['is_default' => true]);
        $this->spare = $this->warehouse('NORTH');
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $this->bin = app(LocationService::class)->create($this->wh, ['code' => 'M1']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'TAPE-01', 'name' => 'Tape', 'uom' => 'ea', 'standard_cost' => 3]);
            app(StockLedger::class)->receive($this->item->fresh(), 10, 3, $this->wh, ['bin_id' => $this->bin->id]);
        });
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    public function test_an_answer_is_computed_from_records_with_its_period_and_sources_and_recorded(): void
    {
        $this->in(function () {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'LATE-1', [['item_id' => $this->item->id, 'qty' => 50]], ['required_by' => now()->addHours(3)], $this->manager);
            app(FulfilmentOrders::class)->release($o);
        });

        $q = $this->in(fn () => app(AssistantDesk::class)->ask('Which orders are at risk of being late?', $this->manager));
        $answer = json_decode($q->answer, true);
        $this->assertSame('orders_at_risk', $answer['topic']);
        $this->assertSame(1, count($answer['rows']));
        $this->assertSame(1, $answer['rows'][0]['short_lines']);
        $this->assertNotEmpty($answer['sources']);
        $this->assertSame('the next 24 hours', $answer['period']['label']);
        $rec = $answer['recommendations'][0];
        foreach (['basis', 'reason', 'assumptions', 'impact', 'next', 'approval'] as $k) {
            $this->assertNotEmpty($rec[$k], "A recommendation is missing its {$k} (WMS-141).");
        }
        $this->assertSame('TEMPLATE', $q->narration_source);
        $this->assertNotNull($q->latency_ms);
    }

    public function test_the_assistant_cannot_see_more_than_the_person_asking(): void
    {
        $answer = $this->in(fn () => app(WarehouseAssistant::class)->answer('Which supplier has the worst delivery accuracy?', $this->viewer));
        $this->assertTrue($answer['refused'], 'A viewer was shown confidential supplier performance.');
        $this->assertSame([], $answer['rows']);

        // An investigation exists on the item: investigators see which, others only that one exists.
        $line = $this->in(function () {
            $count = app(Counting::class)->open(['warehouse_id' => $this->wh->id, 'scope' => 'ITEMS', 'scope_ids' => [$this->item->id], 'recount_qty' => 100], $this->manager);
            app(Counting::class)->record($count->lines()->first(), 7, $this->manager);

            return $count;
        });
        $case = $this->in(fn () => app(ProtectionCases::class)->open(['title' => 'Tape shrinkage', 'description' => 'x'], $this->manager));
        DB::table('mfg_loss_findings')->where('id', $case->id)->update(['subject_type' => 'STOCK_ITEM', 'subject_id' => $this->item->id]);

        $forViewer = $this->in(fn () => app(WarehouseAssistant::class)->answer('Why is TAPE-01 showing a stock variance?', $this->viewer));
        $flag = collect($forViewer['flags'])->pluck('message')->join(' ');
        $this->assertStringNotContainsString($case->number, $flag, 'An investigation number was shown to somebody who may not investigate.');
        $this->assertStringContainsString('investigators can see it', $flag);
        $forManager = $this->in(fn () => app(WarehouseAssistant::class)->answer('Why is TAPE-01 showing a stock variance?', $this->manager));
        $this->assertStringContainsString($case->number, collect($forManager['flags'])->pluck('message')->join(' '));
    }

    public function test_a_draft_is_made_once_only_within_the_askers_rights_and_can_be_withdrawn(): void
    {
        // Buying is due at MAIN; NORTH holds the same item idle for over a year.
        DB::table('fin_stock_items')->where('id', $this->item->id)->update(['reorder_level' => 30]);
        $northBin = $this->in(fn () => app(LocationService::class)->create($this->spare, ['code' => 'N1']));
        $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 40, 3, $this->spare, ['bin_id' => $northBin->id]));

        $q = $this->in(fn () => app(AssistantDesk::class)->ask('What stock could we transfer instead of buying?', $this->manager));
        $answer = json_decode($q->answer, true);
        $this->assertSame('NORTH', $answer['rows'][0]['spare_in']);
        $this->assertContains('TRANSFER_DRAFT', $answer['recommendations'][0]['actions']);

        $a = $this->in(fn () => app(AssistantDesk::class)->draft($q->uuid, 0, 'TRANSFER_DRAFT', $this->manager));
        $again = $this->in(fn () => app(AssistantDesk::class)->draft($q->uuid, 0, 'TRANSFER_DRAFT', $this->manager));
        $this->assertSame($a->id, $again->id, 'Asking twice made two transfers.');
        $order = TransferOrder::query()->where('number', $a->result_reference)->firstOrFail();
        $this->assertSame('DRAFT', $order->status, 'The assistant approved a transfer by itself.');

        $this->in(fn () => app(AssistantDesk::class)->withdraw($a->uuid, $this->manager, 'Bought locally instead'));
        $this->assertSame('CANCELLED', $order->fresh()->status);

        $mover = $this->person('mover', [RoleCatalog::VIEW]);
        $q2 = $this->in(fn () => app(AssistantDesk::class)->ask('What stock could we transfer instead of buying?', $mover));
        $this->assertTrue((bool) $q2->refused, 'Someone without planning or move rights was shown buying advice.');
    }

    public function test_an_invented_figure_in_the_wording_is_thrown_away(): void
    {
        config(['warehouse.assistant_narration' => true]);
        $agent = \Mockery::mock(AIAgentInterface::class);
        $agent->shouldReceive('ask')->andReturn(new AgentResponse('There are 999 orders late.', 'fake', 'fake', 10, 10, 20, 5, true));
        $registry = \Mockery::mock(AgentRegistry::class);
        $registry->shouldReceive('forTask')->andReturn($agent);
        $this->app->instance(AgentRegistry::class, $registry);

        $answer = $this->in(fn () => app(WarehouseAssistant::class)->answer('Which orders are at risk of being late?', $this->manager));
        $words = $this->in(fn () => app(WarehouseNarrator::class)->narrate($answer));
        $this->assertSame('TEMPLATE', $words['source']);
        $this->assertStringNotContainsString('999', $words['text']);
    }

    public function test_feedback_is_recorded_and_quality_is_measured(): void
    {
        $q = $this->in(fn () => app(AssistantDesk::class)->ask('Show stock expiring in the next 30 days.', $this->manager));
        try {
            $this->in(fn () => app(AssistantDesk::class)->feedback($q->uuid, null, 'INCORRECT', '', $this->manager));
            $this->fail('A wrong answer was flagged without saying what was wrong.');
        } catch (RuntimeException) {
        }
        $this->in(fn () => app(AssistantDesk::class)->feedback($q->uuid, null, 'INCORRECT', 'Missed lot B7', $this->manager));
        $this->in(fn () => app(AssistantDesk::class)->feedback($q->uuid, null, 'CORRECT', null, $this->viewer));

        $m = $this->in(fn () => app(AssistantDesk::class)->quality($this->company->id, now()->toDateString(), now()->toDateString()));
        $this->assertSame(1, $m['questions']);
        $this->assertEquals(50.0, $m['accuracy']);
        $this->assertSame(1, $m['flagged_incorrect']);

        $this->actingAs($this->viewer)->get(route('warehouse.assistant.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Assistant/Index'));
        $this->actingAs($this->viewer)->get(route('warehouse.assistant.quality'))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('warehouse.assistant.ask'), ['question' => 'Show stock expiring in the next 30 days.'])->assertRedirect();
        $this->assertSame(1, DB::table('wms_assistant_queries')->where('user_id', $this->viewer->id)->count());
    }
}
