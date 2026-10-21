<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\LossDetector;
use App\Modules\Operations\Manufacturing\Services\LossInvestigation;
use App\Modules\Operations\Manufacturing\Services\LossRules;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MFG-012: detectors raise findings from posted transactions with a baseline, evidence, an estimate
 * and a confidence; investigation keeps a detected variance, a verified loss and a realised saving
 * apart, and the history cannot be rewritten.
 */
class LossPreventionTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private FinStockItem $steel;

    private Item $frame;

    private $analyst;

    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $analyst = Role::firstOrCreate(['name' => 'mfg test loss analyst', 'guard_name' => 'web']);
        $analyst->syncPermissions(array_values(array_diff(RoleCatalog::all(), [RoleCatalog::APPROVE_LOSS])));
        $manager = Role::firstOrCreate(['name' => 'mfg test loss manager', 'guard_name' => 'web']);
        $manager->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_LOSS, RoleCatalog::INVESTIGATE_LOSS, RoleCatalog::APPROVE_LOSS]);
        $this->analyst = $this->makeUser('loss-analyst@example.test', $analyst);
        $this->manager = $this->makeUser('loss-manager@example.test', $manager);
        $this->grantModuleTier($this->analyst);
        $this->grantModuleTier($this->manager);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)
            ->update(['require_material_for_approval' => false, 'require_routing' => false, 'require_qc_before_putaway' => false]));
        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$this->steel, 2, 0, 'kg']]);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->steel->fresh(), 500, 10, $this->store, ['reference' => 'GRN-1']));
    }

    /** An order for 10 frames that used all 20 kg of steel but made only $good, scrapping $scrapped. */
    private function wasteful(float $good, float $scrapped = 0): PO
    {
        return $this->asCompany($this->company, function () use ($good, $scrapped) {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => 10, 'due_date' => now()->addDays(5)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->analyst);
            $life = app(ProductionOrderLifecycle::class);
            $exec = app(ProductionExecution::class);
            $life->plan($o, $this->analyst);
            $life->approve($o->fresh(), $this->analyst);
            $life->release($o->fresh(), $this->analyst);
            $life->start($o->fresh(), $this->analyst);
            $exec->issue($o->fresh(), $o->components()->firstOrFail(), 20, $this->analyst);
            $exec->receiveOutput($o->fresh(), $good, $this->analyst);
            if ($scrapped > 0) {
                $exec->recordLoss($o->fresh(), $scrapped, 0, $this->analyst, 'Bent in the jig');
            }
            $life->sendToQuality($o->fresh(), $this->analyst);
            $life->complete($o->fresh(), $this->analyst);

            return $o->fresh();
        });
    }

    private function scan(?string $only = null): array
    {
        return $this->asCompany($this->company, fn () => app(LossDetector::class)->scan($this->company->id, $only));
    }

    private function finding(string $category): LossFinding
    {
        return $this->asCompany($this->company, fn () => LossFinding::query()->where('category', $category)->firstOrFail());
    }

    public function test_excess_material_and_scrap_are_found_with_their_baseline_evidence_and_estimate(): void
    {
        $order = $this->wasteful(6, 1);   // 7 units made or scrapped needed 14 kg; 20 kg went in

        $raised = $this->scan();
        $this->assertSame(1, $raised['EXCESS_MATERIAL']);
        $this->assertSame(1, $raised['HIGH_SCRAP']);

        $f = $this->finding('EXCESS_MATERIAL');
        $this->assertSame('ORDER', $f->subject_type);
        $this->assertSame($order->id, $f->subject_id);
        $this->assertEquals(20.0, $f->actual_value);
        $this->assertEquals(14.0, $f->baseline_value);
        $this->assertEquals(60.0, $f->estimated_impact, '6 kg too much at 10 a kg');
        $this->assertSame('HIGH', $f->confidence);
        $this->assertSame(LossFinding::DETECTED, $f->status);
        $this->assertSame('Stock mfg_issue', $f->evidence[0]['type']);
        $this->assertNotEmpty($f->recommended_action);
        $this->assertNotNull($f->due_date);

        $scrap = $this->finding('HIGH_SCRAP');
        $this->assertEquals(14.29, $scrap->actual_value, '1 of 7 scrapped');
        $this->assertEquals(5.0, $scrap->baseline_value);

        // Scanning again does not raise what is already open.
        $this->assertSame(0, array_sum($this->scan()));
    }

    public function test_stock_shortfalls_expired_lots_downtime_and_supplier_failures_are_found(): void
    {
        $ledger = app(StockLedger::class);
        $this->asCompany($this->company, fn () => $ledger->count($this->steel->fresh(), $this->store, 470, []));   // 30 kg short: 300

        $glue = $this->stockItem('GLUE', 'l', 4);
        $this->asCompany($this->company, function () use ($ledger, $glue) {
            $lot = $ledger->lot($glue, 'G-OLD', ['expires_on' => now()->subDays(3)->toDateString()]);
            $ledger->receive($glue->fresh(), 25, 4, $this->store, ['reference' => 'GRN-2', 'lot_id' => $lot->id]);
        });

        $centre = $this->asCompany($this->company, fn () => WorkCentre::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'code' => 'PRS', 'name' => 'Press', 'cost_per_hour' => 300, 'labour_rate_per_hour' => 100, 'is_active' => true, 'concurrent_jobs' => 1]));
        $breakdown = $this->asCompany($this->company, fn () => DowntimeReason::query()->where('code', 'BRK')->first()
            ?? DowntimeReason::create(['company_id' => $this->company->id, 'code' => 'BRK', 'name' => 'Breakdown', 'category' => 'BREAKDOWN', 'is_planned' => false, 'is_active' => true]));
        $this->asCompany($this->company, fn () => DowntimeEvent::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'work_centre_id' => $centre->id, 'reason_id' => $breakdown->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addMinutes(120), 'minutes' => 120]));

        $supplier = (int) DB::table('suppliers')->insertGetId(['company_name' => 'Cheap Steel', 'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->asCompany($this->company, function () use ($supplier) {
            foreach (range(1, 3) as $i) {
                Inspection::create(['company_id' => $this->company->id, 'number' => "QI-T{$i}", 'stage' => 'INCOMING', 'stock_item_id' => $this->steel->id,
                    'qty' => 10, 'supplier_id' => $supplier, 'result' => Inspection::FAILED, 'status' => Inspection::CLOSED, 'inspected_at' => now()->subDays($i)]);
            }
        });

        $raised = $this->scan();
        $this->assertSame(1, $raised['UNEXPLAINED_STOCK_LOSS']);
        $this->assertEquals(300.0, $this->finding('UNEXPLAINED_STOCK_LOSS')->estimated_impact);
        $this->assertSame('CYCLE_COUNT', $this->finding('UNEXPLAINED_STOCK_LOSS')->evidence[0]['source']);

        $expired = $this->finding('EXPIRED_OBSOLETE');
        $this->assertSame('LOT', $expired->subject_type);
        $this->assertEquals(100.0, $expired->estimated_impact);

        $down = $this->finding('EXCESS_DOWNTIME');
        $this->assertEquals(100.0, $down->actual_value, 'All the recorded time was lost: nothing ran.');
        $this->assertSame('MEDIUM', $down->confidence);

        $sq = $this->finding('SUPPLIER_QUALITY');
        $this->assertSame('Cheap Steel', $sq->subject_label);
        $this->assertCount(3, $sq->evidence);
    }

    public function test_a_variance_becomes_a_loss_only_when_verified_and_a_saving_only_when_evidenced_and_approved(): void
    {
        $this->wasteful(6);
        $this->scan('EXCESS_MATERIAL');
        $f = $this->finding('EXCESS_MATERIAL');
        $inv = app(LossInvestigation::class);

        $this->actingAs($this->analyst)->post(route('manufacturing.loss.findings.act', [$f->uuid, 'verify']), ['amount' => 80, 'evidence' => 'x'])->assertSessionHas('error');
        $this->actingAs($this->analyst)->post(route('manufacturing.loss.findings.act', [$f->uuid, 'investigate']), ['notes' => 'Checking issue slips'])->assertSessionHas('success');
        $this->actingAs($this->analyst)->post(route('manufacturing.loss.findings.act', [$f->uuid, 'verify']), ['amount' => 75, 'evidence' => 'Issue slips show 7.5 kg drawn twice'])->assertSessionHas('success');
        $f->refresh();
        $this->assertSame(LossFinding::VERIFIED, $f->status);
        $this->assertEquals(75.0, $f->verified_loss);
        $this->assertEquals(80.0, $f->estimated_impact, 'The detector\'s estimate is kept, not overwritten.');
        $this->assertSame($this->analyst->id, $f->owner_id);

        $this->asCompany($this->company, fn () => $inv->plan($f, 'Issue by weighed kit, not by bundle', null, $this->analyst));
        try {
            $this->asCompany($this->company, fn () => $inv->result($f->fresh(), 'Kitting introduced', 500, null, $this->analyst));
            $this->fail('A saving was recorded without evidence.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('evidence', $e->getMessage());
        }
        $this->asCompany($this->company, fn () => $inv->result($f->fresh(), 'Kitting introduced', 500, 'Steel per frame 2.4 kg → 2.0 kg over 40 orders', $this->analyst));
        $f->refresh();
        $this->assertTrue($f->requires_approval);

        $summary = $this->asCompany($this->company, fn () => $inv->summary());
        $this->assertEquals(0.0, $summary['realised_saving'], 'Not realised until approved.');
        $this->assertEquals(500.0, $summary['saving_awaiting_approval']);
        $this->assertEquals(75.0, $summary['verified_loss']);

        $this->actingAs($this->analyst)->post(route('manufacturing.loss.findings.act', [$f->uuid, 'close']))->assertSessionHas('error');
        $this->actingAs($this->analyst)->post(route('manufacturing.loss.findings.act', [$f->uuid, 'approve']))->assertForbidden();
        $this->actingAs($this->manager)->post(route('manufacturing.loss.findings.act', [$f->uuid, 'approve']), ['notes' => 'Agreed with finance'])->assertSessionHas('success');
        $this->actingAs($this->analyst)->post(route('manufacturing.loss.findings.act', [$f->uuid, 'close']))->assertSessionHas('success');
        $this->assertSame(LossFinding::CLOSED, $f->fresh()->status);
        $this->assertEquals(500.0, $this->asCompany($this->company, fn () => $inv->summary())['realised_saving']);

        // The history is complete and cannot be rewritten.
        $actions = DB::table('mfg_loss_events')->where('finding_id', $f->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['DETECTED', 'INVESTIGATING', 'VERIFIED', 'ACTION_PLANNED', 'RESULT', 'APPROVED', 'CLOSED'], $actions);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('mfg_loss_events')->where('finding_id', $f->id)->update(['notes' => 'nothing happened']);
    }

    public function test_the_investigator_cannot_approve_their_own_finding_and_overdue_findings_escalate(): void
    {
        $this->wasteful(6);
        $this->scan('EXCESS_MATERIAL');
        $f = $this->finding('EXCESS_MATERIAL');
        $inv = app(LossInvestigation::class);
        $this->asCompany($this->company, function () use ($f, $inv) {
            $inv->investigate($f, $this->manager);
            $inv->verify($f->fresh(), 20000, 'Count sheets attached', $this->manager);   // above the approval limit
        });
        $this->assertTrue($f->fresh()->requires_approval);
        try {
            $this->asCompany($this->company, fn () => $inv->approve($f->fresh(), $this->manager));
            $this->fail('The investigator approved their own finding.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('somebody other than the investigator', $e->getMessage());
        }

        DB::table('mfg_loss_findings')->where('id', $f->id)->update(['due_date' => now()->subDays(2)->toDateString()]);
        $this->assertSame(1, $this->asCompany($this->company, fn () => $inv->escalateOverdue($this->company->id)));
        $this->assertSame(0, $this->asCompany($this->company, fn () => $inv->escalateOverdue($this->company->id)), 'Not again the same day.');
        $this->assertSame(1, $f->fresh()->escalation_level);
    }

    public function test_rules_switch_detectors_off_and_the_screens_answer_to_their_permissions(): void
    {
        $this->actingAs($this->manager)->post(route('manufacturing.loss.rules.update', 'EXCESS_MATERIAL'), [
            'is_active' => false, 'threshold' => 5, 'min_count' => 1, 'min_impact' => 0, 'lookback_days' => 30, 'approval_over' => 10000, 'due_days' => 14,
        ])->assertSessionHas('success');
        $this->actingAs($this->analyst)->post(route('manufacturing.loss.rules.update', 'HIGH_SCRAP'), [])->assertForbidden();
        $this->wasteful(6, 1);
        $raised = $this->scan();
        $this->assertArrayNotHasKey('EXCESS_MATERIAL', $raised);
        $this->assertSame(1, $raised['HIGH_SCRAP']);

        $page = $this->actingAs($this->analyst)->get(route('manufacturing.loss.findings.index'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Loss/Findings', $page['component']);
        // 500 kg of steel on hand against 20 kg used in 90 days is excess stock too.
        $this->assertEqualsCanonicalizing(['HIGH_SCRAP', 'OVERSTOCK'], array_column($page['props']['findings'], 'category'));
        $this->assertSame('LOW', $this->finding('OVERSTOCK')->confidence);
        $show = $this->actingAs($this->analyst)->get(route('manufacturing.loss.findings.show', $this->finding('HIGH_SCRAP')->uuid))->assertOk()->viewData('page');
        $this->assertSame('DETECTED', $show['props']['history'][0]['action']);
        $this->actingAs($this->analyst)->get(route('manufacturing.loss.rules'))->assertOk();
        $this->actingAs($this->analyst)->post(route('manufacturing.loss.findings.scan'))->assertSessionHas('success');
        $this->actingAs($this->outsider)->get(route('manufacturing.loss.findings.index'))->assertForbidden();

        // The loss cards now sit in the production hub; only theirs are compared.
        $cards = array_values(array_filter(array_column($this->actingAs($this->analyst)->get('/manufacturing/production')->assertOk()->viewData('page')['props']['cards'], 'path'), fn ($p) => str_starts_with($p, '/manufacturing/loss/')));
        $this->assertEqualsCanonicalizing(['/manufacturing/loss/findings', '/manufacturing/loss/rules'], $cards);

        $this->artisan('mfg:scan-losses', ['--company' => $this->company->id])->assertSuccessful();
    }
}
