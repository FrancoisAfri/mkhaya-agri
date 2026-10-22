<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\ExportConsignment;
use App\Modules\Agri\Models\ExportDocument;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\MrlLimit;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\ResidueOverride;
use App\Modules\Agri\Models\ResidueTest;
use App\Modules\Agri\Services\CertificationEvidence;
use App\Modules\Agri\Services\CertificationSchemes;
use App\Modules\Agri\Services\ProduceDispatches;
use App\Modules\Agri\Services\ResidueControls;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Residues and MRLs (comparison per market, banned actives at spray time and at dispatch, the
 * missing-result rule, audited overrides, CSV import), certification checklists (template install,
 * automatic evidence, the self-assessment and its PDF) and export document packs (generation into
 * Document Management, missing-data validation, shipping blocked without the pack, versions).
 */
class ResiduesCertificationExportTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->openBooks();
    }

    private function rc(): ResidueControls
    {
        return app(ResidueControls::class);
    }

    private function planted(Farm $farm, $field, string $code = 'LEM-1', array $markets = []): CropPlan
    {
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId('CITRUS'), 'code' => $code, 'planned_area' => 10, 'area_unit' => 'ha', 'planned_planting_on' => today()->subMonths(5)->toDateString(),
            'planned_harvest_on' => today()->toDateString(), 'expected_yield' => 40, 'yield_unit' => 't'])->assertSessionHasNoErrors();
        $plan = CropPlan::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();
        if ($markets) {
            $plan->forceFill(['target_markets' => $markets])->saveQuietly();
        }

        return $plan->fresh();
    }

    /** A harvest lot recorded directly (for the comparisons that need no stock). */
    private function lot(Farm $farm, CropPlan $plan, string $code, array $markets = []): ProduceLot
    {
        return $this->asCompany($this->company, fn () => ProduceLot::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'code' => $code,
            'kind' => 'harvest', 'product' => 'Lemons', 'crop_type_id' => $plan->crop_type_id, 'crop_plan_id' => $plan->id, 'field_id' => $plan->field_id,
            'quantity' => 100, 'remaining' => 100, 'unit' => 'bins', 'status' => 'available', 'produced_on' => today()->toDateString(), 'target_markets' => $markets ?: null]));
    }

    private function recordTest(Farm $farm, ProduceLot $lot, array $results, ?UploadedFile $cert = null)
    {
        return $this->actingAs($this->manager)->post(route('agri.residues.tests.store'), array_filter(['farm_id' => $farm->uuid, 'laboratory' => 'Hearshaw & Kinnes', 'lab_reference' => 'HK-1',
            'sample_type' => 'lot', 'produce_lot_id' => $lot->id, 'sampled_on' => today()->toDateString(), 'results' => $results, 'certificate' => $cert]));
    }

    private function starter(): void
    {
        $this->actingAs($this->manager)->post(route('agri.residues.starter'))->assertSessionHasNoErrors();
    }

    /**
     * Harvest into a cold room, pack, order for a buyer, pick and count: a load ready to dispatch.
     *
     * @return array{0: FulfilmentOrder, 1: ProduceLot, 2: CropPlan}
     */
    private function loadReady(Farm $farm, CropPlan $plan, ?string $country = null): array
    {
        $cold = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\FarmInventory::class)->addStore($farm, ['kind' => 'cold_room', 'name' => 'Cold room']));
        $bulk = $this->stockItem('LEM-BULK', 'kg', 4);
        $carton = $this->stockItem('LEM-C15', 'ctn', 60);
        $this->actingAs($this->manager)->post(route('agri.crops.harvests.store', $plan->uuid), ['harvested_on' => today()->toDateString(), 'quantity' => 1000, 'unit' => 'kg',
            'grade' => 'Field run', 'destination' => 'store', 'stock_item_id' => $bulk->id, 'unit_value' => 4, 'store_id' => $cold->uuid, 'storage_location' => 'Bay 1',
            'measuring_method' => 'weighbridge', 'inspection_outcome' => 'passed', 'override_reason' => 'Test harvest'])->assertSessionHasNoErrors();
        $fieldLot = ProduceLot::withoutGlobalScopes()->where('kind', 'harvest')->where('crop_plan_id', $plan->id)->sole();
        $this->actingAs($this->manager)->post(route('agri.packhouse.operations.store'), ['farm_id' => $farm->uuid, 'kind' => 'packing',
            'inputs' => [['lot_id' => $fieldLot->uuid, 'quantity' => 900]],
            'outputs' => [['product' => 'Lemons 15kg carton', 'grade' => 'Class 1', 'quantity' => 60, 'unit' => 'ctn', 'stock_item_id' => $carton->id, 'store_id' => $cold->uuid]],
            'loss_reason' => 'Culls', 'workers' => 2, 'labour_minutes' => 60])->assertSessionHasNoErrors();
        $packed = ProduceLot::withoutGlobalScopes()->where('kind', 'packed')->latest('id')->firstOrFail();
        $this->actingAs($this->manager);
        $party = $this->asCompany($this->company, fn () => app(\App\Modules\Shared\Party\PartyDirectory::class)->create(['company_id' => $this->company->id, 'kind' => 'customer',
            'name' => $country ? "Importer {$country}" : 'Local DC', 'country' => $country, 'address' => 'Dock 4'], 'agri'));
        $order = $this->asCompany($this->company, fn () => app(ProduceDispatches::class)->order($farm, ['party_id' => $party->id, 'deliver_on' => today()->addDay()->toDateString()],
            [['product' => 'Lemons 15kg carton', 'grade' => 'Class 1', 'quantity' => 40, 'unit' => 'ctn', 'price' => 12.5]], $this->manager))->load('lines');
        $this->actingAs($this->manager)->post(route('agri.packhouse.orders.pick', $order->uuid), ['items' => [['order_line_id' => $order->lines->first()->id, 'lot_id' => $packed->id,
            'quantity' => 40]], 'vehicle' => 'Reefer 1'])->assertSessionHasNoErrors();
        $load = FulfilmentOrder::query()->latest('id')->firstOrFail();
        $counts = $load->packages()->with('contents')->get()->flatMap->contents->mapWithKeys(fn ($c) => [$c->id => (float) $c->qty])->all();
        $this->actingAs($this->admin)->post(route('agri.packhouse.dispatches.verify', $load->uuid), ['counts' => $counts, 'seal_no' => 'SEAL-1'])->assertSessionHasNoErrors();

        return [$load->fresh(), $packed, $plan];
    }

    private function sprayed(Farm $farm, CropPlan $plan, string $active, int $phi = 3): FieldActivity
    {
        $item = $this->stockItem('CHEM-'.strtoupper(substr(md5($active), 0, 6)), 'l', 50);
        $this->stockOnFarm($farm, $item, 50, 50);
        $this->asCompany($this->company, fn () => ChemicalProduct::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'stock_item_id' => $item->id, 'registration_no' => 'L 1',
            'active_ingredient' => "{$active} 480 g/l", 'storage_class' => 'toxic', 'withholding_days' => $phi, 'restricted' => false, 'registration_expires_on' => today()->addYear()->toDateString()]));
        $this->actingAs($this->manager)->post(route('agri.crops.activities.store'), ['farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid, 'activity_type' => 'spraying',
            'title' => "Spray {$active}", 'planned_on' => today()->subDays(10)->toDateString()])->assertSessionHasNoErrors();
        $activity = FieldActivity::withoutGlobalScopes()->latest('id')->firstOrFail();
        $response = $this->actingAs($this->manager)->post(route('agri.crops.inputs.store', $activity->uuid), ['stock_item_id' => $item->id, 'quantity' => 5, 'withholding_days' => $phi]);
        $response->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $activity->uuid), ['action' => 'complete',
            'completed_at' => today()->subDays(10)->setTime(8, 0)->toDateTimeString()])->assertSessionHasNoErrors();

        return $activity->fresh();
    }

    // ── A. residues ──────────────────────────────────────────────────────────────────────────

    public function test_a_residue_result_is_compared_with_the_mrl_of_each_target_market(): void
    {
        $farm = $this->farm('RIV');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'B1'), 'LEM-1', ['EU', 'ZA']);
        $this->starter();
        $this->assertTrue(MrlLimit::withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_starter', true)->where('crop_code', 'CITRUS')->exists());

        $over = $this->lot($farm, $plan, 'L-OVER');
        $this->recordTest($farm, $over, [['active_ingredient' => 'Imazalil', 'measured_mg_kg' => 4.6], ['active_ingredient' => 'Thiabendazole', 'below_loq' => true]],
            UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'))->assertSessionHasNoErrors();
        $test = ResidueTest::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertNotNull($test->certificate_data, 'The lab certificate is kept in the database (base64).');
        $this->assertSame('CITRUS', $test->crop_code);

        $a = $this->asCompany($this->company, fn () => $this->rc()->assess($over->fresh()));
        $this->assertSame('exceeds', $a['markets']['EU']['status'], 'Imazalil 4.6 is over the EU limit of 4.');
        $this->assertSame('pass', $a['markets']['ZA']['status'], '…and within South Africa\'s 5.');
        $this->assertSame('exceeds', $a['overall']);

        $ok = $this->lot($farm, $plan, 'L-OK');
        $this->recordTest($farm, $ok, [['active_ingredient' => 'Imazalil', 'measured_mg_kg' => 2.1]])->assertSessionHasNoErrors();
        $this->assertSame('pass', $this->asCompany($this->company, fn () => $this->rc()->assess($ok->fresh()))['overall']);

        // No specific MRL: the EU default 0.01 applies; China has no default, so it is flagged, not failed.
        $other = $this->lot($farm, $plan, 'L-OTHER', ['EU', 'CN']);
        $this->recordTest($farm, $other, [['active_ingredient' => 'Novaluron', 'measured_mg_kg' => 0.02]])->assertSessionHasNoErrors();
        $a = $this->asCompany($this->company, fn () => $this->rc()->assess($other->fresh()));
        $this->assertSame('exceeds', $a['markets']['EU']['status']);
        $this->assertSame('pass', $a['markets']['CN']['status']);
        $this->assertStringContainsString('No CN MRL', implode(' ', $a['markets']['CN']['notes']));

        $untested = $this->lot($farm, $plan, 'L-NONE');
        $this->assertSame('not_tested', $this->asCompany($this->company, fn () => $this->rc()->assess($untested->fresh()))['overall']);

        $this->actingAs($this->manager)->get(route('agri.residues', ['tab' => 'lots', 'farm' => $farm->uuid]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Modules/Agri/Residues/Index')->has('data.lots', 4));
        foreach (['tests', 'risks', 'limits', 'restrictions', 'markets'] as $tab) {
            $this->actingAs($this->manager)->get(route('agri.residues', ['tab' => $tab, 'farm' => $farm->uuid]))->assertOk()
                ->assertInertia(fn (AssertableInertia $p) => $p->component('Modules/Agri/Residues/Index')->where('tab', $tab));
        }
    }

    public function test_a_banned_active_is_warned_at_spray_time_and_blocks_dispatch_until_an_audited_override(): void
    {
        $farm = $this->farm('RIV');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'B1'), 'LEM-1', ['ZA']);
        // Banned in the market the produce is for (domestic here, so the export pack does not come into it).
        $this->actingAs($this->manager)->post(route('agri.residues.restrictions.store'), ['active_ingredient' => 'Chlorpyrifos', 'market_code' => 'ZA', 'status' => 'banned'])
            ->assertSessionHasNoErrors();

        $item = $this->stockItem('CHEM-CPF', 'l', 50);
        $this->stockOnFarm($farm, $item, 50, 50);
        $this->asCompany($this->company, fn () => ChemicalProduct::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'stock_item_id' => $item->id, 'registration_no' => 'L 2',
            'active_ingredient' => 'Chlorpyrifos 480 g/l', 'storage_class' => 'toxic', 'withholding_days' => 3, 'restricted' => false, 'registration_expires_on' => today()->addYear()->toDateString()]));
        $this->actingAs($this->manager)->post(route('agri.crops.activities.store'), ['farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid, 'activity_type' => 'spraying',
            'title' => 'Spray', 'planned_on' => today()->subDays(10)->toDateString()])->assertSessionHasNoErrors();
        $activity = FieldActivity::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.store', $activity->uuid), ['stock_item_id' => $item->id, 'quantity' => 5, 'withholding_days' => 3])
            ->assertSessionHasNoErrors()->assertSessionHas('warning', fn ($w) => str_contains((string) $w, 'Chlorpyrifos') && str_contains((string) $w, 'banned in ZA'));
        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $activity->uuid), ['action' => 'complete',
            'completed_at' => today()->subDays(10)->setTime(8, 0)->toDateTimeString()])->assertSessionHasNoErrors()->assertSessionHas('warning');

        [$load, $packed] = $this->loadReady($farm, $plan->fresh());
        $this->assertSame('banned', $this->asCompany($this->company, fn () => $this->rc()->assess($packed->fresh()))['markets']['ZA']['status'],
            'The packed lot inherits what was sprayed on its crop.');
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.dispatch', $load->uuid))
            ->assertSessionHasErrors('dispatch');
        $this->assertTrue((fn ($m) => str_contains(implode(' ', (array) $m), 'banned in ZA'))(session('errors')->get('dispatch')), implode(' ', session('errors')->get('dispatch')));
        $this->assertSame(0, $load->shipments()->count());

        // The manager may not override (no permission in this test's role? they have it) — a worker may not.
        $this->actingAs($this->worker)->post(route('agri.residues.override', $load->uuid), ['reason' => 'Buyer accepts the lot for processing only.'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.residues.override', $load->uuid), ['reason' => 'short'])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('agri.residues.override', $load->uuid), ['reason' => 'Buyer accepts the lot for juicing; letter on file.'])->assertSessionHasNoErrors();
        $o = ResidueOverride::withoutGlobalScopes()->sole();
        $this->assertSame([$this->manager->id, 'ZA'], [(int) $o->authorised_by, $o->market_code]);
        $this->assertNotEmpty($o->blocks);
        $this->assertTrue(DB::table('activity_log')->where('subject_type', ResidueOverride::class)->where('subject_id', $o->id)->where('causer_id', $this->manager->id)->exists(),
            'The override is audited.');
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.dispatch', $load->uuid))->assertSessionHasNoErrors();
        $this->assertSame(1, $load->shipments()->count());

        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'dispatch', 'farm' => $farm->uuid]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('data.dispatches.0.residue.market', 'ZA')->where('data.dispatches.0.residue.override.reason', 'Buyer accepts the lot for juicing; letter on file.'));
    }

    public function test_a_market_that_requires_a_result_blocks_an_untested_lot_and_the_risk_list_flags_it(): void
    {
        $farm = $this->farm('RIV');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'B1'), 'LEM-1', ['ZA']);
        $za = $this->asCompany($this->company, fn () => $this->rc()->markets($this->company->id)->get('ZA'));
        $this->actingAs($this->manager)->patch(route('agri.residues.markets.update', $za->uuid), ['name' => $za->name, 'countries' => ['ZA'], 'requires_result' => true,
            'default_target' => true, 'is_active' => true])->assertSessionHasNoErrors();
        $this->sprayed($farm, $plan, 'Imazalil', 10);   // withholding ends after the planned harvest: test before picking
        $risks = $this->asCompany($this->company, fn () => $this->rc()->preHarvestRisks($farm));
        $this->assertSame(['Imazalil', false], [$risks[0]['active'], $risks[0]['tested']]);

        [$load, $packed] = $this->loadReady($farm, $plan->fresh());
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.dispatch', $load->uuid))
            ->assertSessionHasErrors('dispatch');
        $this->assertTrue((fn ($m) => str_contains(implode(' ', (array) $m), 'no residue result'))(session('errors')->get('dispatch')), implode(' ', session('errors')->get('dispatch')));

        $this->recordTest($farm, $packed, [['active_ingredient' => 'Imazalil', 'measured_mg_kg' => 1.2]])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.dispatch', $load->uuid))->assertSessionHasNoErrors();
    }

    public function test_official_mrls_are_imported_from_csv(): void
    {
        $this->farm('RIV');
        $csv = "active_ingredient;crop_code;market_code;mrl_mg_kg;source;effective_from\nImazalil;CITRUS;EU;4;EU Reg 2023/1;2026-03-01\nPyrimethanil;GRAPE;UK;5,5;UK MRL;2026-01-01\n";
        $this->actingAs($this->manager)->post(route('agri.residues.import'), ['file' => UploadedFile::fake()->createWithContent('mrls.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(5.5, (float) MrlLimit::withoutGlobalScopes()->where('active_key', 'pyrimethanil')->where('market_code', 'UK')->value('mrl_mg_kg'), 0.0001);
        // The same rows again replace, not duplicate; a bad row stops the whole file.
        $this->actingAs($this->manager)->post(route('agri.residues.import'), ['file' => UploadedFile::fake()->createWithContent('mrls.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertSame(2, MrlLimit::withoutGlobalScopes()->count());
        $this->actingAs($this->manager)->post(route('agri.residues.import'), ['file' => UploadedFile::fake()->createWithContent('bad.csv',
            "active_ingredient,crop_code,market_code,mrl_mg_kg\nCaptan,APPLE,XX,10\nCaptan,APPLE,EU,abc\n")])->assertSessionHasErrors('file');
        $this->assertSame(2, MrlLimit::withoutGlobalScopes()->count());
        $this->actingAs($this->worker)->post(route('agri.residues.import'), ['file' => UploadedFile::fake()->createWithContent('mrls.csv', $csv)])->assertForbidden();
    }

    // ── B. certification ─────────────────────────────────────────────────────────────────────

    public function test_a_checklist_template_installs_as_an_editable_scheme_with_automatic_evidence_and_an_audit_pdf(): void
    {
        $farm = $this->farm('RIV');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'B1'), 'LEM-1', ['EU']);
        $this->starter();
        $this->sprayed($farm, $plan, 'Imazalil', 3);

        $this->actingAs($this->worker)->post(route('agri.certification.install'), ['template' => 'globalgap-ifa-v6-smart-fv'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.certification.install'), ['template' => 'globalgap-ifa-v6-smart-fv'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.certification.install'), ['template' => 'globalgap-ifa-v6-smart-fv'])->assertSessionHasErrors('template');
        $scheme = DB::table('sheq_compliance_schemes')->where('company_id', $this->company->id)->where('code', 'globalgap-ifa-v6-smart-fv')->first();
        $this->assertSame('published', $scheme->status, 'The organisation\'s own copy, published and editable in SHEQ.');
        $this->assertSame('major', DB::table('sheq_compliance_scheme_clauses')->where('scheme_id', $scheme->id)->where('clause', 'FV 06.03')->value('category'));

        $this->actingAs($this->manager)->post(route('agri.certification.adopt', $farm->uuid), ['scheme_id' => $scheme->id])->assertSessionHasNoErrors();
        $farm = $farm->fresh();
        $this->assertGreaterThan(30, DB::table('sheq_legal_register_lines')->where('site_id', $farm->sheq_site_id)->where('regulatory_pack_id', 'scheme:globalgap-ifa-v6-smart-fv')->count());

        $a = $this->asCompany($this->company, fn () => app(CertificationSchemes::class)->assessment($farm, $scheme));
        $point = fn (string $ref) => collect($a['points'])->firstWhere('clause', $ref);
        $this->assertSame('compliant', $point('FV 06.02')['status'], 'The spray record has operator, field and withholding period.');
        $this->assertSame(CertificationEvidence::SPRAY_RECORDS, $point('FV 06.02')['evidence'][0]['type']);
        $this->assertSame('no_evidence', $point('FV 06.04')['status'], 'No residue test yet.');
        $this->assertFalse($a['ready']);

        // A residue test over the EU MRL makes the residue control point non-compliant.
        $lot = $this->lot($farm, $plan, 'L-1');
        $this->recordTest($farm, $lot, [['active_ingredient' => 'Imazalil', 'measured_mg_kg' => 4.6]])->assertSessionHasNoErrors();
        $a = $this->asCompany($this->company, fn () => app(CertificationSchemes::class)->assessment($farm, $scheme));
        $this->assertSame('non_compliant', collect($a['points'])->firstWhere('clause', 'FV 06.04')['status']);

        // The assessor answers a documentary control point; a non-compliant answer needs a comment.
        $this->actingAs($this->manager)->post(route('agri.certification.answer', $farm->uuid), ['scheme_id' => $scheme->id, 'clause' => 'AF 07.01', 'answer' => 'non_compliant'])
            ->assertSessionHasErrors('comment');
        $this->actingAs($this->manager)->post(route('agri.certification.answer', $farm->uuid), ['scheme_id' => $scheme->id, 'clause' => 'AF 07.01', 'answer' => 'compliant'])
            ->assertSessionHasNoErrors();
        $a = $this->asCompany($this->company, fn () => app(CertificationSchemes::class)->assessment($farm, $scheme));
        $this->assertSame('compliant', collect($a['points'])->firstWhere('clause', 'AF 07.01')['status']);
        $major = $a['levels']['major'];
        $this->assertEqualsWithDelta(round($major['compliant'] / $major['applicable'] * 100, 1), $major['pct'], 0.01);
        $this->assertSame(100, $major['required']);

        // The SHEQ register computes the same automatic evidence.
        $line = DB::table('sheq_legal_register_lines')->where('site_id', $farm->sheq_site_id)->where('clause', 'FV 06.02')->first();
        $this->asCompany($this->company, fn () => app(\App\Modules\Operations\Sheq\Services\LegalRegisterService::class)->compute($this->company->id, $line->uuid));
        $this->assertSame('COMPLIANT', DB::table('sheq_legal_register_lines')->where('id', $line->id)->value('status'));

        $pdf = $this->actingAs($this->manager)->get(route('agri.certification.pdf', $farm->uuid).'?scheme='.$scheme->id);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->manager)->get(route('agri.certification.index', ['farm' => $farm->uuid, 'scheme' => $scheme->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Modules/Agri/Certification/Index')->has('templates', 3)->where('assessment.adopted', true));
    }

    // ── C. export documents ──────────────────────────────────────────────────────────────────

    public function test_an_export_load_ships_only_with_its_document_pack_which_is_validated_and_versioned(): void
    {
        $farm = $this->farm('RIV');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'B1'), 'LEM-1');
        [$load, $packed] = $this->loadReady($farm, $plan, 'NL');
        // Residues for the EU: tested and within the limits, so only the pack is in the way.
        $this->starter();
        $this->recordTest($farm, $packed, [['active_ingredient' => 'Imazalil', 'measured_mg_kg' => 1.0]])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.dispatch', $load->uuid))
            ->assertSessionHasErrors('dispatch');
        $this->assertTrue((fn ($m) => str_contains(implode(' ', (array) $m), 'export load'))(session('errors')->get('dispatch')), implode(' ', session('errors')->get('dispatch')));

        $this->actingAs($this->worker)->post(route('agri.exports.save', $load->uuid), ['incoterm' => 'FOB'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.exports.save', $load->uuid), ['incoterm' => 'FOB', 'transport_mode' => 'sea'])->assertSessionHasNoErrors();
        $c = ExportConsignment::withoutGlobalScopes()->with('lines')->sole();
        $this->assertSame(['NL', 'Importer NL', 'SEAL-1'], [$c->destination_country, $c->consignee_name, $c->seal_no]);
        $line = $c->lines->sole();
        $this->assertSame([40.0, 600.0, '0805.50'], [$line->cartons, $line->net_kg, $line->hs_code], '15 kg cartons × 40; HS code for citrus.');

        // Missing data is named before anything is generated.
        $this->actingAs($this->manager)->post(route('agri.exports.generate', $c->uuid))
            ->assertSessionHasErrors('documents');
        $this->assertTrue((fn ($m) => str_contains(implode(' ', (array) $m), 'Port of loading') && str_contains(implode(' ', (array) $m), 'Gross kg'))(session('errors')->get('documents')), implode(' ', session('errors')->get('documents')));
        $this->assertSame(0, ExportDocument::withoutGlobalScopes()->count());

        $this->actingAs($this->manager)->post(route('agri.exports.codes.store', $farm->uuid), ['kind' => 'PUC', 'code' => 'R1234'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.exports.codes.store', $farm->uuid), ['kind' => 'PHC', 'code' => 'P5678'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.exports.save', $load->uuid), ['notify_party' => 'Agent BV, Rotterdam', 'port_of_loading' => 'Durban', 'port_of_discharge' => 'Rotterdam',
            'container_no' => 'MSKU1234567', 'vessel' => 'MSC Example', 'voyage' => 'V12', 'booking_ref' => 'BK-1', 'departure_on' => today()->addDays(3)->toDateString(),
            'inspection_point' => 'Durban FPT', 'inspection_on' => today()->addDay()->toDateString(), 'temperature_setpoint_c' => -0.5, 'exporter_address' => 'Letsitele'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.exports.lines', $c->uuid), ['lines' => [['id' => $line->uuid, 'gross_kg' => 650, 'pallets' => 1, 'puc' => 'R1234',
            'pallet_ids' => 'P-0001']]])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.exports.generate', $c->uuid))->assertSessionHasNoErrors();
        $docs = ExportDocument::withoutGlobalScopes()->where('consignment_id', $c->id)->get();
        $this->assertCount(6, $docs);
        $dm = \App\Modules\Operations\DocumentManagement\Models\Document::withoutGlobalScopes()->findOrFail($docs->firstWhere('kind', 'phytosanitary')->document_id);
        $this->assertSame('export_document', $dm->document_type);
        $this->assertTrue($dm->links()->where('linkable_type', ExportConsignment::class)->where('linkable_id', $c->id)->exists(), 'Filed against the consignment…');
        $this->assertTrue($dm->links()->where('relation', 'farm')->exists(), '…and the farm, in Document Management.');

        // A change regenerates what was generated, as a new version; the old one is kept as superseded.
        $this->actingAs($this->manager)->post(route('agri.exports.save', $load->uuid), ['vessel' => 'MSC Other'])->assertSessionHasNoErrors();
        $si = ExportDocument::withoutGlobalScopes()->where('consignment_id', $c->id)->where('kind', 'shipping_instruction')->orderBy('version')->get();
        $this->assertSame([[1, 'superseded'], [2, 'current']], $si->map(fn ($d) => [(int) $d->version, $d->status])->all());
        $this->assertSame($si[0]->document_id, $si[1]->document_id, 'The same Document Management document, with a new version.');
        $this->assertSame(2, (int) $dm->fresh()->version_count);

        $this->actingAs($this->manager)->get(route('agri.exports.index', ['farm' => $farm->uuid]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Modules/Agri/Exports/Index')->where('loads.0.export', true)->has('loads.0.consignment.pack', 6)
                ->where('loads.0.refusal', null));
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.dispatch', $load->uuid))->assertSessionHasNoErrors();
        $this->assertSame(1, $load->shipments()->count());
    }

    // ── permissions and isolation ────────────────────────────────────────────────────────────

    public function test_permissions_and_tenant_isolation(): void
    {
        $farm = $this->farm('RIV');
        foreach ([route('agri.residues.home'), route('agri.certification.index'), route('agri.exports.index')] as $url) {
            $this->actingAs($this->outsider)->get($url)->assertForbidden();
        }
        $this->actingAs($this->worker)->post(route('agri.residues.starter'))->assertForbidden();
        $this->actingAs($this->worker)->post(route('agri.residues.restrictions.store'), ['active_ingredient' => 'X', 'market_code' => 'EU', 'status' => 'banned'])->assertForbidden();

        $this->starter();
        $other = \App\Modules\Tenancy\Models\Company::create(['name' => 'Ander Boerdery']);
        $this->entitle($other);
        $role = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'agri test org admin', 'guard_name' => 'web']);
        $stranger = $this->makeUser('admin@ander.test', $role, $other);
        $theirFarm = $this->farm('ANDER', $other);
        $limit = MrlLimit::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $this->actingAs($stranger)->delete(route('agri.residues.limits.destroy', $limit->uuid))->assertNotFound();
        $this->actingAs($stranger)->get(route('agri.residues', ['tab' => 'limits']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('data.limits', 0));
        $this->actingAs($stranger)->get(route('agri.certification.pdf', $farm->uuid))->assertNotFound();
        $this->actingAs($this->manager)->post(route('agri.exports.codes.store', $theirFarm->uuid), ['kind' => 'PUC', 'code' => 'X'])->assertNotFound();
        $this->assertSame(0, $this->asCompany($other, fn () => MrlLimit::query()->count()));
    }
}
