<?php

namespace Tests\Feature\Manufacturing;

use App\Core\AI\Contracts\AIAgentInterface;
use App\Core\AI\Registry\AgentRegistry;
use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\AssistantAction;
use App\Modules\Operations\Manufacturing\Models\AssistantQuery;
use App\Modules\Operations\Manufacturing\Models\AssistantRecommendation;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\AssistantDesk;
use App\Modules\Operations\Manufacturing\Services\AssistantNarrator;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * MFG-019: answers computed from records with period, sources, kinds and data warnings; a model may
 * only word them; recommendations are recorded and measured; drafts are created, and anything that
 * buys or changes production waits for somebody else's approval.
 */
class AssistantTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private FinStockItem $steel;

    private Item $frame;

    private $manager;

    private $buyer;

    private $viewer;

    /** What the fake model says next. */
    public static string $reply = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        config(['manufacturing.assistant_narration' => true]);

        $all = Role::firstOrCreate(['name' => 'mfg test ops manager', 'guard_name' => 'web']);
        $all->syncPermissions(array_values(array_diff(RoleCatalog::all(), [RoleCatalog::APPROVE_PLAN, RoleCatalog::APPROVE_ORDERS])));
        $buyer = Role::firstOrCreate(['name' => 'mfg test buyer', 'guard_name' => 'web']);
        $buyer->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::USE_ASSISTANT, RoleCatalog::APPROVE_PLAN, RoleCatalog::APPROVE_ORDERS, RoleCatalog::VIEW_ORDERS]);
        $viewer = Role::firstOrCreate(['name' => 'mfg test assistant viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::USE_ASSISTANT]);
        $this->manager = $this->makeUser('assist-manager@example.test', $all);
        $this->buyer = $this->makeUser('assist-buyer@example.test', $buyer);
        $this->viewer = $this->makeUser('assist-viewer@example.test', $viewer);
        foreach ([$this->manager, $this->buyer, $this->viewer] as $u) {
            $this->grantModuleTier($u);
        }

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)
            ->update(['require_material_for_approval' => false, 'require_routing' => false]));
        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$this->steel, 2, 0, 'kg']]);

        // A model that says whatever the test tells it to.
        $agent = new class implements AIAgentInterface
        {
            public function ask(AgentRequest $request): AgentResponse
            {
                return new AgentResponse(AssistantTest::$reply, 'fake', 'fake', 10, 10, 20, 1, true);
            }

            public function stream(AgentRequest $request): Generator
            {
                yield AssistantTest::$reply;
            }

            public function providerName(): string
            {
                return 'fake';
            }
        };
        $this->mock(AgentRegistry::class, fn ($m) => $m->shouldReceive('forTask')->andReturn($agent));
    }

    /** An approved order for 5 frames, due yesterday, with no steel in stock. */
    private function lateShortOrder(): PO
    {
        return $this->asCompany($this->company, function () {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => 5, 'due_date' => now()->subDay()->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->manager);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->manager);
            $life->approve($o->fresh(), $this->buyer);

            return $o->fresh();
        });
    }

    private function ask($user, string $q): array
    {
        return $this->actingAs($user)->postJson(route('manufacturing.assistant.ask'), ['question' => $q])->assertOk()->json();
    }

    public function test_an_answer_is_figures_from_records_with_kinds_sources_and_warnings_and_a_refusal_shows_none(): void
    {
        $order = $this->lateShortOrder();
        self::$reply = 'One order is late.';

        $a = $this->ask($this->manager, 'Which production orders are likely to miss their due dates?');
        $answer = $a['answer'];
        $this->assertSame('late_orders', $answer['topic']);
        $this->assertSame($order->number, $answer['rows'][0]['order']);
        $this->assertContains($answer['rows'][0]['kind'], ['FORECAST', 'ESTIMATE']);
        $this->assertStringContainsString('already overdue', $answer['rows'][0]['why']);
        $this->assertStringContainsString('short of STEEL', $answer['rows'][0]['why']);
        $this->assertSame('MISSING', $answer['flags'][0]['level'], 'The order was never scheduled; the finish is an estimate and says so.');
        $this->assertNotEmpty($answer['sources']);
        $this->assertSame(['PURCHASE_REQUEST', 'TASK'], $answer['recommendations'][0]['actions']);
        $this->assertSame('MODEL', $a['narration_source']);

        // Somebody without access to orders gets no figures at all.
        $refused = $this->ask($this->viewer, 'Which production orders are likely to miss their due dates?');
        $this->assertTrue($refused['answer']['refused']);
        $this->assertSame([], $refused['answer']['rows']);
        $this->assertSame('TEMPLATE', $refused['narration_source']);

        // Recorded, in this company, with the recommendation and its baseline.
        $q = $this->asCompany($this->company, fn () => AssistantQuery::query()->where('user_id', $this->manager->id)->firstOrFail());
        $this->assertSame($this->company->id, (int) $q->company_id);
        $rec = $this->asCompany($this->company, fn () => AssistantRecommendation::query()->where('query_id', $q->id)->firstOrFail());
        $this->assertSame('ORDER_LATENESS', $rec->metric);
        $this->assertSame('PRODUCTION_ORDER', $rec->subject_type);

        // Another company sees none of it.
        $other = Company::create(['name' => 'Elsewhere Ltd']);
        $this->assertSame(0, $this->asCompany($other, fn () => AssistantQuery::query()->count()));
    }

    public function test_a_model_that_adds_a_number_is_not_believed(): void
    {
        $this->lateShortOrder();
        self::$reply = 'Forty-two orders are late, 42 in total, costing R 9,999.';
        $a = $this->ask($this->manager, 'Which orders will miss their due date?');
        $this->assertSame('TEMPLATE', $a['narration_source'], 'The invented figures are discarded.');
        $this->assertStringContainsString('likely to miss their due date', $a['narration']);

        $narrator = app(AssistantNarrator::class);
        $this->assertTrue($narrator->grounded('Margin fell from 31.5% to 22%.', '{"margin_before":31.5,"margin_now":22}'));
        $this->assertFalse($narrator->grounded('Margin fell 9.5 points.', '{"margin_before":31.5,"margin_now":22}'));
    }

    public function test_drafts_investigations_and_approvals_from_a_recommendation(): void
    {
        $order = $this->lateShortOrder();
        $this->ask($this->manager, 'Which production orders are likely to miss their due dates?');
        $rec = $this->asCompany($this->company, fn () => AssistantRecommendation::query()->firstOrFail());

        // A purchase is only proposed; its proposer cannot approve it.
        $this->actingAs($this->manager)->post(route('manufacturing.assistant.draft', $rec->uuid), ['kind' => 'PURCHASE_REQUEST'])->assertSessionHas('success');
        $action = $this->asCompany($this->company, fn () => AssistantAction::query()->where('kind', 'PURCHASE_REQUEST')->firstOrFail());
        $this->assertSame('DRAFT', $action->status);
        $this->assertSame(0, DB::table('purchase_requests')->where('justification', 'like', '%manufacturing assistant%')->count());
        $this->actingAs($this->manager)->post(route('manufacturing.assistant.decide', [$action->uuid, 'approve']))->assertSessionHas('error');

        $this->actingAs($this->buyer)->post(route('manufacturing.assistant.decide', [$action->uuid, 'approve']), ['notes' => 'Agreed'])->assertSessionHas('success');
        $action->refresh();
        $this->assertSame('EXECUTED', $action->status);
        $this->assertSame($this->buyer->id, (int) $action->decided_by);
        $this->assertSame('draft', DB::table('purchase_requests')->where('id', $action->result_id)->value('status'), 'Procurement still decides.');
        $this->assertEquals(10.0, (float) DB::table('purchase_request_items')->where('purchase_request_id', $action->result_id)->value('quantity'));

        // A task is created at once; an action the recommendation does not offer is refused.
        $this->actingAs($this->manager)->post(route('manufacturing.assistant.draft', $rec->uuid), ['kind' => 'TASK', 'owner_id' => $this->manager->id])->assertSessionHas('success');
        $this->actingAs($this->manager)->post(route('manufacturing.assistant.draft', $rec->uuid), ['kind' => 'PRIORITISE_ORDER'])->assertSessionHas('error');
        $this->actingAs($this->viewer)->post(route('manufacturing.assistant.draft', $rec->uuid), ['kind' => 'TASK'])->assertForbidden();
        $this->assertNotSame(1, (int) $order->fresh()->priority, 'Nothing changed the order.');

        // An investigation request opens a loss finding straight away -- detected, not verified.
        $this->stockSteel();
        $centre = $this->downtimeOn();
        $this->ask($this->manager, 'Which machines caused the most production downtime?');
        $down = $this->asCompany($this->company, fn () => AssistantRecommendation::query()->where('topic', 'downtime')->firstOrFail());
        $this->assertSame((int) $centre->id, (int) $down->subject_id);
        $this->actingAs($this->manager)->post(route('manufacturing.assistant.draft', $down->uuid), ['kind' => 'INVESTIGATION'])->assertSessionHas('success');
        $finding = $this->asCompany($this->company, fn () => LossFinding::query()->where('dedupe_key', "ASSISTANT:{$down->uuid}")->firstOrFail());
        $this->assertSame('EXCESS_DOWNTIME', $finding->category);
        $this->assertSame(LossFinding::DETECTED, $finding->status);
        $this->assertEquals(0.0, $finding->estimated_impact, 'The assistant claims no money it has not measured.');
    }

    public function test_recommendations_are_measured_against_what_happened_next(): void
    {
        $this->downtimeOn();
        $this->ask($this->manager, 'Which machines caused the most downtime in the last 7 days?');
        $rec = $this->asCompany($this->company, fn () => AssistantRecommendation::query()->firstOrFail());
        $this->assertEquals(120.0, $rec->baseline_value);

        $this->assertSame(0, $this->asCompany($this->company, fn () => app(AssistantDesk::class)->measureDue()), 'Too soon.');
        $this->travel(8)->days();
        $this->assertSame(1, $this->asCompany($this->company, fn () => app(AssistantDesk::class)->measureDue()));
        $rec->refresh();
        $this->assertEquals(0.0, $rec->measured_value);
        $this->assertStringContainsString('Improved', $rec->outcome);

        $this->flushSession();
        $page = $this->actingAs($this->manager)->get(route('manufacturing.assistant.recommendations'))->assertOk()->viewData('page');
        $this->assertStringContainsString('Improved', $page['props']['recommendations'][0]['outcome']);
        $this->artisan('mfg:measure-assistant', ['--company' => $this->company->id])->assertSuccessful();
    }

    public function test_the_screens_and_the_mkhaya_copilot_answer_from_the_same_figures(): void
    {
        $ask = $this->actingAs($this->viewer)->get(route('manufacturing.assistant.index'))->assertOk()->viewData('page');
        $this->assertFalse(collect($ask['props']['questions'])->firstWhere('topic', 'late_orders')['allowed']);
        $this->actingAs($this->outsider)->get(route('manufacturing.assistant.index'))->assertForbidden();
        $this->actingAs($this->outsider)->postJson(route('manufacturing.assistant.ask'), ['question' => 'Which machines caused downtime?'])->assertForbidden();

        // The assistant cards now sit in the reports hub; only theirs are compared.
        $cards = array_values(array_filter(array_column($this->actingAs($this->manager)->get('/manufacturing/reports')->assertOk()->viewData('page')['props']['cards'], 'path'), fn ($p) => str_starts_with($p, '/manufacturing/assistant/')));
        $this->assertEqualsCanonicalizing(['/manufacturing/assistant/ask', '/manufacturing/assistant/recommendations'], $cards);

        // The company-wide Mkhaya copilot gets the same permission-checked figures.
        $this->lateShortOrder();
        $this->actingAs($this->manager);
        $builder = app(\App\Core\AI\Services\ERPContextBuilder::class);
        $data = (fn ($q) => $this->getManufacturingData($q))->call($builder, 'Which production orders will miss their due dates?');
        $this->assertTrue($data['permitted']);
        $this->assertNotEmpty($data['rows']);
        $this->assertArrayNotHasKey('url', $data['rows'][0]);
        $this->actingAs($this->viewer);
        $this->assertFalse((fn ($q) => $this->getManufacturingData($q))->call($builder, 'Which production orders will miss their due dates?')['permitted']);
    }

    private function stockSteel(): void
    {
        $this->asCompany($this->company, fn () => app(\App\Modules\Finance\Accounting\Services\StockLedger::class)->receive($this->steel->fresh(), 100, 10, $this->store, ['reference' => 'GRN']));
    }

    private function downtimeOn(): WorkCentre
    {
        return $this->asCompany($this->company, function () {
            $c = WorkCentre::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'PRS', 'name' => 'Press',
                'cost_per_hour' => 100, 'is_active' => true, 'concurrent_jobs' => 1]);
            $r = DowntimeReason::query()->where('code', 'BRK')->first()
                ?? DowntimeReason::create(['company_id' => $this->company->id, 'code' => 'BRK', 'name' => 'Breakdown', 'category' => 'BREAKDOWN', 'is_planned' => false, 'is_active' => true]);
            DowntimeEvent::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'work_centre_id' => $c->id, 'reason_id' => $r->id,
                'started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)->addMinutes(120), 'minutes' => 120]);

            return $c;
        });
    }
}
