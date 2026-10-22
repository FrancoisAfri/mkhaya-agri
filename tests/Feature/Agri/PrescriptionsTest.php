<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\MachineData\MachineDataSync;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Models\MachineConnection;
use App\Modules\Agri\Models\MachineLink;
use App\Modules\Agri\Models\MachineOperation;
use App\Modules\Agri\Models\Prescription;
use App\Modules\Agri\Models\PrescriptionFile;
use App\Modules\Agri\Models\PrescriptionZone;
use App\Modules\Agri\Services\BoundaryFiles;
use App\Modules\Agri\Services\FieldMaps;
use App\Modules\Agri\Services\PrescriptionFiles;
use App\Modules\Agri\Services\Prescriptions;
use App\Modules\Agri\Support\Geo;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Variable-rate prescriptions: a rate per management zone or per NDVI class (flipped or not),
 * totals and cost against a flat rate, a shapefile our own reader reads back with its rates, a
 * well-formed ISO-XML task, four-eyes approval before anything goes to a machine, the send keeping
 * the platform's file id, the planned field work with its input, and the as-applied comparison.
 */
class PrescriptionsTest extends AgriTestCase
{
    private Farm $north;

    private FarmField $l1;

    private FinStockItem $lan;

    /** @var array<string, FieldZone> */
    private array $zones = [];

    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->openBooks();
        $this->catalogue();
        $this->north = $this->farm('NORTH');
        $this->member($this->manager, $this->north);
        $this->member($this->worker, $this->north, 'worker');
        $this->l1 = $this->field($this->north, 'L1', 20);
        $this->lan = $this->stockItem('LAN', 'kg', 10);
        // A field 0.008° square with four strips west to east: weak, middling, strong, and a rock outcrop.
        $this->asCompany($this->company, function () {
            $maps = app(FieldMaps::class);
            $maps->setFieldBoundary($this->l1, $this->rect(28.000, 28.008), $this->admin);
            $this->l1->refresh();
            foreach ([['West', 'low', 28.000, 28.002], ['Middle', 'medium', 28.002, 28.004], ['East', 'high', 28.004, 28.007], ['Rock', 'exclude', 28.007, 28.008]] as [$name, $class, $a, $b]) {
                $this->zones[$name] = $maps->saveZone($this->l1, ['name' => $name, 'zone_class' => $class], $this->rect($a, $b), $this->admin);
            }
        });
    }

    // ── building ─────────────────────────────────────────────────────────────────────────────

    public function test_from_zones_a_rate_per_class_with_totals_cost_and_the_flat_rate_comparison(): void
    {
        $rx = $this->draft(['base_rate' => 200, 'step_pct' => 20]);
        $z = $this->zoneRates($rx);
        $this->assertSame(['West' => 160.0, 'Middle' => 200.0, 'East' => 240.0, 'Rock' => 0.0], $z, 'Low −20 %, medium the base, high +20 %, excluded none.');

        $zones = PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->get()->keyBy('label');
        $expected = 160 * $zones['West']->area_ha + 200 * $zones['Middle']->area_ha + 240 * $zones['East']->area_ha;
        $this->assertEqualsWithDelta($expected, $rx->total_quantity, 0.01);
        $this->assertEqualsWithDelta($expected * 10, $rx->total_cost, 0.05, 'Cost from the stock item: R10 a kg.');
        $this->assertEqualsWithDelta(240 * $zones['East']->area_ha, $zones['East']->quantity, 0.001);

        $s = app(Prescriptions::class)->summary($rx);
        $treated = $zones['West']->area_ha + $zones['Middle']->area_ha + $zones['East']->area_ha;
        $this->assertEqualsWithDelta($treated, $s['treated_ha'], 0.0001);
        $this->assertEqualsWithDelta(200 * $treated, $s['flat_quantity'], 0.01, 'The flat rate over the treated area.');
        $this->assertEqualsWithDelta($s['flat_cost'] - $rx->total_cost, $s['saving_cost'], 0.01);
        $this->assertLessThan(0, $s['saving_cost'], 'The strong strip is the widest, so variable rate uses more here.');

        // A rate set by hand on one zone: totals follow.
        $east = $zones['East'];
        $this->actingAs($this->admin)->patch(route('agri.prescriptions.update', $rx->uuid), ['rates' => [$east->uuid => 220]])->assertSessionHasNoErrors();
        $rx->refresh();
        $this->assertEqualsWithDelta($expected - 20 * $east->area_ha, $rx->total_quantity, 0.01);
        $this->assertSame(1, $rx->version, 'A draft that was never approved or exported keeps its version.');
    }

    public function test_from_ndvi_zone_statistics_classified_with_flip_and_by_quantiles(): void
    {
        $this->ndvi($this->l1, [['id' => $this->zones['West']->uuid, 'value' => 0.31], ['id' => $this->zones['Middle']->uuid, 'value' => 0.52], ['id' => $this->zones['East']->uuid, 'value' => 0.70]]);
        $rx = $this->draft(['source' => 'ndvi', 'classes' => 3, 'method' => 'equal_interval', 'flip' => true, 'base_rate' => 200, 'step_pct' => 20]);
        $this->assertSame(['West' => 240.0, 'Middle' => 200.0, 'East' => 160.0, 'Rock' => 0.0], $this->zoneRates($rx), 'Flipped: more on the weaker zones.');
        $zones = PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->get()->keyBy('label');
        $this->assertSame([1, 2, 3, null], [$zones['West']->class, $zones['Middle']->class, $zones['East']->class, $zones['Rock']->class]);
        $this->assertSame(0.52, $zones['Middle']->ndvi);
        $this->assertNotNull($rx->fresh()->imagery_id);

        // Not flipped, two classes by quantile: the weaker half and the stronger half.
        $rx2 = $this->draft(['source' => 'ndvi', 'classes' => 2, 'method' => 'quantile', 'flip' => false, 'base_rate' => 100, 'step_pct' => 10]);
        $this->assertSame(['West' => 95.0, 'Middle' => 95.0, 'East' => 105.0, 'Rock' => 0.0], $this->zoneRates($rx2));
        $this->assertSame([1 => 1, 2 => 1, 3 => 2], array_combine([1, 2, 3], array_values(Prescriptions::classify([0.31, 0.52, 0.70], 2, 'quantile'))));
        $this->assertSame([1, 3, 5], array_values(Prescriptions::classify([0.1, 0.5, 0.9], 5, 'equal_interval')));
    }

    public function test_from_ndvi_points_a_grid_over_the_field_each_cell_classed(): void
    {
        config(['agri.prescriptions.grid_m' => 150]);
        $l2 = $this->field($this->north, 'L2', 30);
        $this->asCompany($this->company, fn () => app(FieldMaps::class)->setFieldBoundary($l2, $this->rect(28.100, 28.106), $this->admin));
        $this->ndvi($l2->fresh(), [['id' => 'c1', 'lng' => 28.1005, 'lat' => -26.0005, 'value' => 0.2], ['id' => 'c2', 'lng' => 28.1055, 'lat' => -26.0005, 'value' => 0.8],
            ['id' => 'c3', 'lng' => 28.1005, 'lat' => -26.0055, 'value' => 0.25], ['id' => 'c4', 'lng' => 28.1055, 'lat' => -26.0055, 'value' => 0.75]]);
        $rx = $this->draft(['field_id' => $l2->uuid, 'source' => 'ndvi', 'classes' => 3, 'base_rate' => 60000, 'step_pct' => 10, 'unit' => 'seeds_ha', 'operation' => 'seeding', 'per_stock_unit' => 80000]);
        $zones = PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->get();
        $this->assertGreaterThan(4, $zones->count());
        $this->assertLessThanOrEqual(250, $zones->count());
        $west = $zones->filter(fn ($z) => Geo::centroid($z->boundary)[0] < 28.102);
        $east = $zones->filter(fn ($z) => Geo::centroid($z->boundary)[0] > 28.104);
        $this->assertSame([1], $west->pluck('class')->unique()->values()->all(), 'The low-NDVI west is the weakest class.');
        $this->assertSame([3], $east->pluck('class')->unique()->values()->all());
        $this->assertSame(66000.0, $east->first()->rate);
        $this->assertEqualsWithDelta($rx->total_quantity / 80000 * 10, $rx->total_cost, 0.05, 'Seeds costed per bag of 80 000.');
    }

    // ── files ────────────────────────────────────────────────────────────────────────────────

    public function test_the_shapefile_reads_back_through_our_own_reader_with_the_rates(): void
    {
        $rx = $this->draft(['base_rate' => 200, 'step_pct' => 20]);
        $file = $this->asCompany($this->company, fn () => app(PrescriptionFiles::class)->ensure($rx, 'shapefile', $this->admin));
        $this->assertSame([1, 'application/zip'], [$file->version, $file->mime]);
        $this->assertSame(hash('sha256', $file->bytes()), $file->sha256, 'Kept in the database, base64.');

        $read = app(BoundaryFiles::class)->read($file->bytes(), $file->file_name);
        $this->assertSame('shapefile', $read['format']);
        $this->assertStringContainsString('WGS', $read['crs']);
        $this->assertCount(4, $read['features']);
        $byZone = collect($read['features'])->keyBy(fn ($f) => $f['properties']['ZONE']);
        $this->assertSame(240.0, $byZone['East']['properties']['RATE']);
        $this->assertSame(160.0, $byZone['West']['properties']['RATE']);
        $this->assertSame(0.0, $byZone['Rock']['properties']['RATE']);
        $this->assertSame('kg/ha', $byZone['East']['properties']['UNIT']);
        $this->assertSame('LAN (test)', $byZone['East']['properties']['PRODUCT']);
        $this->assertEqualsWithDelta((float) $this->zones['East']->area_ha, Geo::hectares($byZone['East']['geometry']), 0.001, 'The polygon comes back the same size.');
        $this->assertSame($file->id, app(PrescriptionFiles::class)->ensure($rx, 'shapefile')->id, 'One file per format and version.');
    }

    public function test_iso_xml_is_well_formed_with_the_partfield_product_task_and_a_treatment_zone_per_rate(): void
    {
        $rx = $this->draft(['base_rate' => 200, 'step_pct' => 20]);
        $file = $this->asCompany($this->company, fn () => app(PrescriptionFiles::class)->ensure($rx, 'isoxml', $this->admin));
        $tmp = tempnam(sys_get_temp_dir(), 'rx-test');
        file_put_contents($tmp, $file->bytes());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $xml = $zip->getFromName('TASKDATA/TASKDATA.XML');
        $zip->close();
        unlink($tmp);
        $this->assertNotFalse($xml, 'The zip holds TASKDATA/TASKDATA.XML, as a monitor expects.');

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'Well-formed XML.');
        $this->assertSame('ISO11783_TaskData', $doc->getName());
        $this->assertSame('4', (string) $doc['VersionMajor']);
        $this->assertSame('LAN (test)', (string) $doc->PDT['B']);
        $this->assertSame('PFD1', (string) $doc->PFD['A']);
        $this->assertSame('1', (string) $doc->PFD->PLN['A'], 'The field boundary as a partfield boundary polygon.');
        $this->assertGreaterThan(3, count($doc->PFD->PLN->LSG->PNT));
        $tsk = $doc->TSK;
        $this->assertSame(['PFD1', '1', '0'], [(string) $tsk['E'], (string) $tsk['G'], (string) $tsk['H']]);
        $rates = [];
        foreach ($tsk->TZN as $tzn) {
            $this->assertSame('0006', (string) $tzn->PDV['A'], 'Setpoint mass per area.');
            $this->assertSame('PDT1', (string) $tzn->PDV['C']);
            $rates[(string) $tzn['A']] = (int) $tzn->PDV['B'];
            if ((string) $tzn['A'] !== '0') {
                $this->assertSame('2', (string) $tzn->PLN['A'], 'A treatment-zone polygon.');
                $this->assertSame('1', (string) $tzn->PLN->LSG['A']);
            }
        }
        sort($rates);
        $this->assertSame([0, 0, 16000, 20000, 24000], $rates, 'mg/m²: 160, 200 and 240 kg/ha; the outside zone and the rock apply nothing.');
        $pnt = $tsk->TZN[1]->PLN->LSG->PNT[0];
        $this->assertEqualsWithDelta(-26.0, (float) $pnt['C'], 0.01, 'C is north (latitude).');
        $this->assertEqualsWithDelta(28.0, (float) $pnt['D'], 0.01, 'D is east (longitude).');
    }

    // ── approval and sending ────────────────────────────────────────────────────────────────

    public function test_four_eyes_approval_is_required_before_a_prescription_is_sent_and_the_send_keeps_their_id(): void
    {
        $deere = $this->deereConnected();
        $rx = $this->draft(['base_rate' => 200, 'step_pct' => 20]);
        $this->actingAs($this->admin)->post(route('agri.prescriptions.send', $rx->uuid), ['provider' => 'deere'])->assertSessionHasErrors('prescription');
        $this->actingAs($this->admin)->post(route('agri.prescriptions.submit', $rx->uuid))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('agri.prescriptions.approve', $rx->uuid))->assertSessionHasErrors('prescription');
        $this->actingAs($this->worker)->post(route('agri.prescriptions.approve', $rx->uuid))->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.prescriptions.approve', $rx->uuid))->assertSessionHasNoErrors();
        $rx->refresh();
        $this->assertSame([Prescription::APPROVED, 1, (int) $this->manager->id], [$rx->status, (int) $rx->approved_version, (int) $rx->approved_by]);
        $this->assertSame(['geojson', 'isoxml', 'shapefile'], PrescriptionFile::withoutGlobalScopes()->where('prescription_id', $rx->id)->where('version', 1)->orderBy('format')->pluck('format')->all());

        // A change after approval is a new version, back in draft: nothing goes out until it is approved again.
        $east = PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->where('label', 'East')->sole();
        $this->actingAs($this->admin)->patch(route('agri.prescriptions.update', $rx->uuid), ['rates' => [$east->uuid => 230]])->assertSessionHasNoErrors();
        $this->assertSame([Prescription::DRAFT, 2, null], [$rx->fresh()->status, $rx->fresh()->version, $rx->fresh()->approved_version]);
        $this->actingAs($this->admin)->post(route('agri.prescriptions.send', $rx->uuid), ['provider' => 'deere'])->assertSessionHasErrors('prescription');
        $this->assertSame(0, count($this->seen['file_post'] ?? []));
        $this->actingAs($this->admin)->post(route('agri.prescriptions.submit', $rx->uuid));
        $this->actingAs($this->manager)->post(route('agri.prescriptions.approve', $rx->uuid))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('agri.prescriptions.send', $rx->uuid), ['provider' => 'deere'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $link = MachineLink::withoutGlobalScopes()->where('kind', 'prescription')->where('local_id', $rx->id)->sole();
        $this->assertSame(['sent', 'file-77', 'uploaded', 2], [$link->status, $link->external_id, $link->remote_status, $link->data['version']]);
        $this->assertSame(Prescription::SENT, $rx->fresh()->status);
        $this->assertSame('RX-'.now()->format('y').'-0001_v2.zip', $this->seen['file_post'][0]['name']);
        $put = $this->seen['file_put'][0];
        $this->assertStringStartsWith('PK', $put->body(), 'The zipped shapefile itself is uploaded.');
        $this->assertSame(PrescriptionFile::withoutGlobalScopes()->where('prescription_id', $rx->id)->where('format', 'shapefile')->where('version', 2)->sole()->sha256, hash('sha256', $put->body()));

        // Sent again: answered from the first, not uploaded twice.
        $this->actingAs($this->admin)->post(route('agri.prescriptions.send', $rx->uuid), ['provider' => 'deere'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->seen['file_post']);
        // A sent prescription is on the record: it is not edited.
        $this->actingAs($this->admin)->patch(route('agri.prescriptions.update', $rx->uuid), ['rates' => [$east->uuid => 100]])->assertSessionHasErrors('prescription');

        // The status the platform reports comes back with the next sync.
        $this->asCompany($this->company, fn () => app(MachineDataSync::class)->sync($deere->fresh(), 'now', $this->admin));
        $this->assertSame('Ready', $link->fresh()->remote_status);
    }

    public function test_planned_work_from_the_prescription_then_as_applied_against_prescribed(): void
    {
        $rx = $this->approved();
        $this->actingAs($this->admin)->post(route('agri.prescriptions.plan', $rx->uuid), ['planned_on' => today()->addDays(3)->toDateString()])->assertSessionHasNoErrors();
        $rx->refresh();
        $activity = FieldActivity::withoutGlobalScopes()->findOrFail($rx->activity_id);
        $this->assertSame(['fertilising', 'planned', (int) $this->l1->id], [$activity->activity_type, $activity->status, (int) $activity->field_id]);
        $input = ActivityInput::withoutGlobalScopes()->where('activity_id', $activity->id)->sole();
        $this->assertSame([(int) $this->lan->id, 'planned'], [(int) $input->stock_item_id, $input->status]);
        $this->assertEqualsWithDelta($rx->total_quantity, (float) $input->quantity, 0.001, 'The whole prescription is planned from the store.');
        $this->actingAs($this->admin)->post(route('agri.prescriptions.plan', $rx->uuid))->assertSessionHasErrors('prescription');

        // The machine did it: CNH FieldOps reports the application with its rate samples.
        $c = MachineConnection::for($this->company->id, 'cnh');
        $c->forceFill(['status' => 'connected', 'org_id' => 'c-9', 'org_name' => 'Boplaas (CNH)'])->save();
        MachineLink::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'connection_id' => $c->id, 'kind' => 'field', 'external_id' => 'cf-1', 'external_name' => 'L1',
            'local_id' => $this->l1->id, 'status' => 'linked', 'matched_by' => 'code']);
        $centre = fn (string $zone) => Geo::centroid(Geo::polygon($this->zones[$zone]->boundary));
        $points = [];
        foreach (['West' => [150, 158], 'Middle' => [205, 199], 'East' => [250, 262]] as $zone => $samples) {
            foreach ($samples as $rate) {
                [$lng, $lat] = $centre($zone);
                $points[] = ['lng' => $lng, 'lat' => $lat, 'rate' => (float) $rate];
            }
        }
        $total = round($rx->total_quantity * 1.04, 1);
        $e = $this->asCompany($this->company, fn () => app(MachineDataSync::class)->queueOperation($c, ['id' => 'cop-1', 'type' => 'application', 'field_id' => 'cf-1',
            'started_at' => now()->subHour()->toIso8601String(), 'ended_at' => now()->toIso8601String(), 'area_ha' => 18.0, 'operator' => 'T. Dlamini',
            'products' => [['name' => 'LAN (test)', 'rate' => 208.0, 'unit' => 'kg_ha', 'total' => $total, 'area_ha' => 18.0]],
            'rate_points' => $points, 'raw' => ['id' => 'cop-1', 'source' => 'test']], $this->admin));
        $this->assertSame('processed', $e->status, (string) $e->error);

        $op = MachineOperation::withoutGlobalScopes()->sole();
        $this->assertSame([(int) $rx->id, (int) $activity->id], [(int) $op->prescription_id, (int) $op->activity_id], 'The operation filled the planned activity.');
        $this->assertSame(1, FieldActivity::withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta($total, (float) $input->fresh()->quantity, 0.001, 'The planned input now holds the as-applied quantity.');
        $this->assertSame(Prescription::APPLIED, $rx->fresh()->status);

        $cmp = app(Prescriptions::class)->compare($rx->fresh());
        $z = collect($cmp['zones'])->keyBy('label');
        $this->assertSame([154.0, 'samples', 2, -3.8], [$z['West']['applied'], $z['West']['basis'], $z['West']['samples'], $z['West']['difference_pct']]);
        $this->assertSame([256.0, 6.7], [$z['East']['applied'], $z['East']['difference_pct']]);
        $this->assertSame(4.0, $cmp['difference_pct']);

        $this->actingAs($this->worker)->get(route('agri.prescriptions.show', $rx->uuid))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Prescriptions/Show')->has('comparison.zones', 4)->where('prescription.status', 'applied'));
    }

    // ── permissions, tenants, pages ─────────────────────────────────────────────────────────

    public function test_permissions_tenant_isolation_and_pages(): void
    {
        $rx = $this->approved();
        $this->actingAs($this->outsider)->get(route('agri.prescriptions.index'))->assertForbidden();
        $this->actingAs($this->worker)->get(route('agri.prescriptions.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Prescriptions/Index')->has('prescriptions', 1)->where('can.plan', false)
                ->where('farms.0.fields', fn ($fields) => collect($fields)->firstWhere('code', 'L1')['zones'] === 4));
        $this->actingAs($this->worker)->post(route('agri.prescriptions.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->worker)->post(route('agri.prescriptions.export', $rx->uuid), ['format' => 'geojson'])->assertForbidden();
        $this->actingAs($this->manager)->get(route('agri.prescriptions.show', $rx->uuid))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Prescriptions/Show')->has('zones', 4)->has('files', 3)->where('can.approve', true)
                ->where('summary.cost', fn ($v) => $v > 0)->has('connections', 2));
        $this->actingAs($this->admin)->get(route('agri.prescriptions.show', $rx->uuid))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('can.drafter', true)->where('can.approve', false));
        $file = PrescriptionFile::withoutGlobalScopes()->where('prescription_id', $rx->id)->where('format', 'geojson')->sole();
        $dl = $this->actingAs($this->worker)->get(route('agri.prescriptions.files.download', [$rx->uuid, $file->uuid]))->assertOk();
        $this->assertSame('FeatureCollection', json_decode($dl->getContent(), true)['type']);

        $other = Company::create(['name' => 'Ander Plaas']);
        $this->entitle($other);
        $otherAdmin = $this->makeUser('admin@ander.test', \App\Core\Role\Models\Role::firstOrCreate(['name' => 'agri test org admin', 'guard_name' => 'web']), $other);
        $this->actingAs($otherAdmin)->get(route('agri.prescriptions.show', $rx->uuid))->assertNotFound();
        $this->actingAs($otherAdmin)->get(route('agri.prescriptions.files.download', [$rx->uuid, $file->uuid]))->assertNotFound();
        $this->actingAs($otherAdmin)->get(route('agri.prescriptions.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('prescriptions', 0));
        $this->assertSame(0, $this->asCompany($other, fn () => PrescriptionZone::query()->count()));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function payload(array $o = []): array
    {
        return $o + ['farm_id' => $this->north->uuid, 'field_id' => $this->l1->uuid, 'operation' => 'fertiliser', 'stock_item_id' => $this->lan->id, 'unit' => 'kg_ha',
            'source' => 'zones', 'base_rate' => 200, 'step_pct' => 20, 'flip' => false];
    }

    private function draft(array $o = []): Prescription
    {
        $before = Prescription::withoutGlobalScopes()->max('id') ?? 0;
        $this->actingAs($this->admin)->post(route('agri.prescriptions.store'), $this->payload($o))->assertSessionHasNoErrors()->assertRedirect();

        return Prescription::withoutGlobalScopes()->where('id', '>', $before)->sole();
    }

    private function approved(): Prescription
    {
        $rx = $this->draft();
        $this->actingAs($this->admin)->post(route('agri.prescriptions.submit', $rx->uuid))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.prescriptions.approve', $rx->uuid))->assertSessionHasNoErrors();

        return $rx->fresh();
    }

    /** @return array<string, float> rate by zone label */
    private function zoneRates(Prescription $rx): array
    {
        return PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->orderByRaw("CASE label WHEN 'West' THEN 0 WHEN 'Middle' THEN 1 WHEN 'East' THEN 2 ELSE 3 END")
            ->pluck('rate', 'label')->map(fn ($v) => (float) $v)->all();
    }

    private function ndvi(FarmField $field, array $cells): void
    {
        $this->asCompany($this->company, fn () => FieldImagery::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $field->farm_id, 'field_id' => $field->id,
            'captured_on' => today()->subDays(3), 'source' => 'Sentinel-2 L2A (CDSE)', 'index' => 'ndvi', 'mean' => 0.5, 'min' => 0.2, 'max' => 0.8, 'cells' => $cells]));
    }

    private function rect(float $west, float $east, float $north = -26.000, float $south = -26.006): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$west, $north], [$east, $north], [$east, $south], [$west, $south], [$west, $north]]]];
    }

    private function deereConnected(): MachineConnection
    {
        config(['agri.machine_data.providers.deere.client_id' => 'deere-client', 'agri.machine_data.providers.deere.client_secret' => 'deere-secret']);
        $c = MachineConnection::for($this->company->id, 'deere');
        $c->putTokens(['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_at' => now()->addHours(12)->timestamp]);
        $c->forceFill(['status' => 'connected', 'org_id' => '4100', 'org_name' => 'Boplaas Boerdery', 'organisations' => [['id' => '4100', 'name' => 'Boplaas Boerdery', 'needs_access' => false]],
            'settings' => ['import_boundaries' => false, 'import_machines' => false, 'import_telemetry' => false, 'import_operations' => false], 'connected_by' => $this->admin->id])->save();
        Http::fake(function (HttpRequest $r) {
            $p = substr((string) parse_url($r->url(), PHP_URL_PATH), strlen('/platform'));
            $this->assertTrue($r->hasHeader('Authorization', 'Bearer at-1'));

            return match (true) {
                $p === '/organizations/4100/files' && $r->method() === 'POST' => tap(Http::response(null, 201, ['Location' => 'https://partnerapi.deere.com/platform/files/file-77']), fn () => $this->seen['file_post'][] = $r),
                $p === '/files/file-77' && $r->method() === 'PUT' => tap(Http::response(null, 204), fn () => $this->seen['file_put'][] = $r),
                $p === '/files/file-77' => Http::response(['id' => 'file-77', 'status' => 'Ready']),
                default => Http::response(['errors' => [['message' => "Unexpected {$r->method()} {$p}"]]], 404),
            };
        });

        return $c;
    }
}
