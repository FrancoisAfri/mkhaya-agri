<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Models\ImageryFlag;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\SatellitePolygon;
use App\Modules\Agri\Models\SatelliteProvider;
use App\Modules\Agri\Models\SatelliteScene;
use App\Modules\Agri\Models\SatelliteUsage;
use App\Modules\Agri\Services\SatelliteImagery;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * Satellite NDVI fetched by the farm itself: per field and zone through the provider the
 * organisation chose, cloudy scenes skipped, nothing stored twice, a drop raising the usual flag,
 * a daily budget, failures retried rather than fatal — and never a real network call.
 */
class SatelliteImageryTest extends AgriTestCase
{
    private Farm $farmA;

    private FarmField $f1;

    private array $zones = [];

    /** Field statistics per date: [mean, min, max, sampleCount, noDataCount]; zones keyed by zone name. */
    private array $fieldDays = [];

    private array $zoneDays = [];

    private bool $failStats = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->catalogue();
        $this->farmA = $this->farm('NORTH');
        $this->member($this->manager, $this->farmA);
        $this->member($this->worker, $this->farmA, 'worker');
        $this->f1 = $this->mappedField($this->farmA, 'L1', 28.0, -26.0);
        $this->zones = $this->asCompany($this->company, fn () => [
            'East' => FieldZone::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'field_id' => $this->f1->id, 'name' => 'East', 'zone_class' => 'high',
                'boundary' => $this->square(28.005, -26.0, 0.005), 'area_ha' => 25, 'status' => 'active']),
            'West' => FieldZone::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'field_id' => $this->f1->id, 'name' => 'West', 'zone_class' => 'low',
                'boundary' => $this->square(28.0, -26.0, 0.005), 'area_ha' => 25, 'status' => 'active']),
        ]);
    }

    // ── Copernicus ───────────────────────────────────────────────────────────────────────────

    public function test_copernicus_token_and_statistics_become_field_and_zone_stats_with_a_picture(): void
    {
        $this->copernicus();
        $d1 = today()->subDays(10)->toDateString();
        $d2 = today()->subDays(5)->toDateString();
        $this->fieldDays = [$d1 => [0.62, 0.40, 0.81, 1000, 50], $d2 => [0.30, 0.1, 0.5, 1000, 700]];   // d2: 70% masked
        $this->zoneDays = ['East' => [$d1 => [0.70, 0.5, 0.85, 500, 10]], 'West' => [$d1 => [0.52, 0.3, 0.7, 500, 40]]];
        $this->fakeCopernicus();

        $this->artisan('agri:satellite', ['--company' => $this->company->id])->assertSuccessful();

        $ndvi = FieldImagery::withoutGlobalScopes()->where('field_id', $this->f1->id)->where('index', 'ndvi')->sole();
        $this->assertSame($d1, $ndvi->captured_on->toDateString());
        $this->assertEqualsWithDelta(0.62, $ndvi->mean, 0.0001);
        $this->assertEqualsWithDelta(5.0, $ndvi->cloud_pct, 0.01, 'Cloud cover is the masked share of the field.');
        $this->assertSame('Sentinel-2 L2A (CDSE)', $ndvi->source);
        $cells = collect($ndvi->cells)->keyBy('name');
        $this->assertEqualsWithDelta(0.70, $cells['East']['value'], 0.0001);
        $this->assertEqualsWithDelta(0.52, $cells['West']['value'], 0.0001);
        $this->assertSame($this->zones['East']->uuid, $cells['East']['id']);
        $this->assertNotNull($cells['East']['lat']);
        $this->assertSame('image/png', $ndvi->image_mime);
        $this->assertStringStartsWith("\x89PNG", base64_decode(FieldImagery::withoutGlobalScopes()->whereKey($ndvi->id)->value('image_data')));
        $this->assertTrue(FieldImagery::withoutGlobalScopes()->where('field_id', $this->f1->id)->where('index', 'ndre')->exists(), 'NDRE comes from the same request.');
        $this->assertTrue(FieldImagery::withoutGlobalScopes()->where('field_id', $this->f1->id)->where('index', 'ndwi')->exists());

        $scenes = SatelliteScene::withoutGlobalScopes()->where('field_id', $this->f1->id)->orderBy('acquired_on')->get();
        $this->assertSame(['stored', 'too_cloudy'], $scenes->pluck('status')->all(), 'The cloudy day is remembered, not stored as a capture.');
        $this->assertTrue($scenes[0]->has_image);
        $this->assertSame(2, $scenes[0]->zones);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'identity.dataspace.copernicus.eu') && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'cdse-client');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v1/statistics') && $r->hasHeader('Authorization', 'Bearer tok-1')
            && $r['aggregation']['aggregationInterval']['of'] === 'P1D' && str_contains($r['aggregation']['evalscript'], 'SCL')
            && $r['input']['data'][0]['type'] === 'sentinel-2-l2a');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v1/process') && $r['input']['data'][0]['dataFilter']['timeRange']['from'] === "{$d1}T00:00:00Z");
        // 1 field + 2 zones + 1 picture; the token is not a paid request.
        $this->assertSame(4, (int) SatelliteUsage::withoutGlobalScopes()->where('company_id', $this->company->id)->value('requests'));
        $this->assertSame('processed', IntegrationEvent::withoutGlobalScopes()->where('integration', 'satellite')->sole()->status);
    }

    public function test_a_scene_is_never_stored_twice_and_the_next_fetch_starts_after_the_last(): void
    {
        $this->copernicus();
        $d1 = today()->subDays(6)->toDateString();
        $this->fieldDays = [$d1 => [0.6, 0.4, 0.8, 1000, 0]];
        $this->fakeCopernicus();
        $svc = app(SatelliteImagery::class);

        $this->asCompany($this->company, fn () => $svc->fetchFarm($this->farmA, 'manual:1'));
        $this->asCompany($this->company, fn () => $svc->fetchFarm($this->farmA, 'manual:2'));
        // The same daily run asked again is answered from the first time.
        $this->artisan('agri:satellite', ['--company' => $this->company->id])->assertSuccessful();
        $this->artisan('agri:satellite', ['--company' => $this->company->id])->assertSuccessful();

        $this->assertSame(1, FieldImagery::withoutGlobalScopes()->where('field_id', $this->f1->id)->where('index', 'ndvi')->count());
        $this->assertSame(1, SatelliteScene::withoutGlobalScopes()->where('field_id', $this->f1->id)->count());
        $from = today()->subDays(5)->toDateString();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/statistics') && $r['aggregation']['timeRange']['from'] === "{$from}T00:00:00Z");
        $this->assertSame(3, IntegrationEvent::withoutGlobalScopes()->where('integration', 'satellite')->count(), 'Two manual fetches and one daily run.');
    }

    public function test_a_big_ndvi_drop_raises_the_imagery_flag_and_a_scouting_check(): void
    {
        $this->copernicus();
        $d1 = today()->subDays(12)->toDateString();
        $d2 = today()->subDays(2)->toDateString();
        $this->fieldDays = [$d1 => [0.70, 0.5, 0.85, 1000, 0], $d2 => [0.55, 0.3, 0.8, 1000, 0]];
        $this->zoneDays = ['East' => [$d1 => [0.72, 0.6, 0.8, 500, 0], $d2 => [0.70, 0.6, 0.8, 500, 0]], 'West' => [$d1 => [0.68, 0.5, 0.8, 500, 0], $d2 => [0.40, 0.2, 0.6, 500, 0]]];
        $this->fakeCopernicus();

        $this->artisan('agri:satellite', ['--company' => $this->company->id])->assertSuccessful();

        $flag = ImageryFlag::withoutGlobalScopes()->sole();   // NDVI flags; NDRE/NDWI are kept beside it without flagging
        $this->assertStringContainsString('NDVI fell', $flag->reason);
        $this->assertSame('West', collect($flag->cells)->first()['name'] ?? null, 'The zone that fell is where to look.');
        $obs = CropObservation::withoutGlobalScopes()->findOrFail($flag->observation_id);
        $this->assertSame('open', $obs->status);
        $this->assertStringContainsString('Sentinel-2 L2A (CDSE)', $obs->description);
        $this->assertEqualsWithDelta(-21.43, FieldImagery::withoutGlobalScopes()->where('index', 'ndvi')->whereDate('captured_on', $d2)->value('change_pct'), 0.01);
    }

    public function test_the_daily_budget_stops_further_requests(): void
    {
        $this->copernicus(['daily_budget' => 4]);
        $d1 = today()->subDays(9)->toDateString();
        $d2 = today()->subDays(4)->toDateString();
        $this->fieldDays = [$d1 => [0.6, 0.4, 0.8, 1000, 0], $d2 => [0.62, 0.4, 0.8, 1000, 0]];
        $this->zoneDays = ['East' => [$d1 => [0.6, 0.5, 0.7, 500, 0], $d2 => [0.6, 0.5, 0.7, 500, 0]], 'West' => [$d1 => [0.6, 0.5, 0.7, 500, 0], $d2 => [0.6, 0.5, 0.7, 500, 0]]];
        $this->fakeCopernicus();
        $f2 = $this->mappedField($this->farmA, 'L2', 28.02, -26.0);

        $this->artisan('agri:satellite', ['--company' => $this->company->id])->assertSuccessful();

        // L1: 1 field + 2 zones + the first picture = 4; the second picture would be the fifth.
        $this->assertSame(4, (int) SatelliteUsage::withoutGlobalScopes()->where('company_id', $this->company->id)->value('requests'));
        $scenes = SatelliteScene::withoutGlobalScopes()->where('field_id', $this->f1->id)->orderBy('acquired_on')->get();
        $this->assertSame([true, false], $scenes->pluck('has_image')->all(), 'The figures are kept; the picture waits.');
        $this->assertSame(0, SatelliteScene::withoutGlobalScopes()->where('field_id', $f2->id)->count(), 'L2 waits for tomorrow.');
        Http::assertSentCount(5);   // token + 4

        $this->asCompany($this->company, fn () => app(SatelliteImagery::class)->fetchFarm($this->farmA, 'manual:x'));
        Http::assertSentCount(5);
    }

    public function test_a_provider_error_is_recorded_and_retried_not_fatal(): void
    {
        $this->copernicus();
        $d1 = today()->subDays(3)->toDateString();
        $this->fieldDays = [$d1 => [0.6, 0.4, 0.8, 1000, 0]];
        $this->failStats = true;
        $this->fakeCopernicus();
        $f2 = $this->mappedField($this->farmA, 'L2', 28.02, -26.0);

        $this->artisan('agri:satellite', ['--company' => $this->company->id])->assertSuccessful();

        $events = IntegrationEvent::withoutGlobalScopes()->where('integration', 'satellite')->get();
        $this->assertCount(2, $events, 'One failing field does not stop the next.');
        $this->assertSame(['failed', 'failed'], $events->pluck('status')->all());
        $this->assertStringContainsString('HTTP 503', $events[0]->error);
        $this->assertSame('out', $events[0]->direction);
        $this->assertSame(0, FieldImagery::withoutGlobalScopes()->count());

        $this->failStats = false;
        $this->travel(10)->minutes();
        $this->artisan('agri:integrations-retry', ['--company' => $this->company->id])->assertSuccessful();
        $this->assertSame(['processed', 'processed'], IntegrationEvent::withoutGlobalScopes()->where('integration', 'satellite')->pluck('status')->all());
        $this->assertSame(1, FieldImagery::withoutGlobalScopes()->where('field_id', $this->f1->id)->where('index', 'ndvi')->count());
        $this->assertSame(1, FieldImagery::withoutGlobalScopes()->where('field_id', $f2->id)->where('index', 'ndvi')->count());
    }

    // ── AgroMonitoring ───────────────────────────────────────────────────────────────────────

    public function test_agromonitoring_registers_polygons_once_and_stores_ndvi_per_field_and_zone(): void
    {
        $this->configure('agromonitoring', ['api_key' => 'agro-key']);
        $d1 = today()->subDays(8);
        $d2 = today()->subDays(3);
        $png = $this->png();
        $scene = fn ($dt, string $type, float $cl, string $poly, float $mean) => ['dt' => $dt, 'type' => $type, 'dc' => 100, 'cl' => $cl,
            'image' => ['ndvi' => "http://api.agromonitoring.com/image/1.0/{$poly}-{$dt}?appid=agro-key"],
            'stats' => ['ndvi' => "http://api.agromonitoring.com/stats/1.0/{$poly}-{$dt}-".($mean * 1000).'?appid=agro-key']];
        Http::fake(function (Request $r) use ($d1, $d2, $scene, $png) {
            $u = $r->url();
            if ($r->method() === 'POST' && str_contains($u, '/polygons')) {
                return Http::response(['id' => 'poly-'.str_replace(['L1 · ', 'L1'], ['', 'field'], $r['name'])], 201);
            }
            if (str_contains($u, '/image/search')) {
                parse_str(parse_url($u, PHP_URL_QUERY), $q);
                $poly = $q['polyid'];
                $mean = ['poly-field' => 0.66, 'poly-East' => 0.71, 'poly-West' => 0.58][$poly];

                return Http::response([
                    $scene($d1->copy()->setTime(8, 30)->timestamp, 'Landsat 8', 20, $poly, $mean - 0.1),
                    $scene($d1->copy()->setTime(8, 10)->timestamp, 'Sentinel-2', 5, $poly, $mean),
                    $scene($d2->copy()->setTime(8, 10)->timestamp, 'Sentinel-2', 70, $poly, 0.3),
                ]);
            }
            if (str_contains($u, '/stats/1.0/')) {
                preg_match('/-(\d+)\?/', $u, $m);

                return Http::response(['mean' => ((int) $m[1]) / 1000, 'min' => 0.2, 'max' => 0.9, 'median' => 0.6, 'std' => 0.1, 'num' => 900]);
            }
            if (str_contains($u, '/image/1.0/')) {
                return Http::response($png, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('unexpected '.$u, 500);
        });

        $this->artisan('agri:satellite', ['--company' => $this->company->id])->assertSuccessful();

        $this->assertSame(['poly-East', 'poly-West', 'poly-field'], SatellitePolygon::withoutGlobalScopes()->pluck('external_id')->sort()->values()->all());
        $ndvi = FieldImagery::withoutGlobalScopes()->where('field_id', $this->f1->id)->where('index', 'ndvi')->sole();
        $this->assertSame('AgroMonitoring', $ndvi->source);
        $this->assertSame($d1->toDateString(), $ndvi->captured_on->toDateString());
        $this->assertEqualsWithDelta(0.66, $ndvi->mean, 0.0001, 'The clearest scene of the day is the one used.');
        $this->assertEqualsWithDelta(0.58, collect($ndvi->cells)->firstWhere('name', 'West')['value'], 0.0001);
        $this->assertSame('image/png', $ndvi->image_mime);
        $this->assertSame('too_cloudy', SatelliteScene::withoutGlobalScopes()->whereDate('acquired_on', $d2)->value('status'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'https://api.agromonitoring.com/stats/1.0/'));

        // A later fetch reuses the registered polygons.
        $this->asCompany($this->company, fn () => app(SatelliteImagery::class)->fetchFarm($this->farmA, 'manual:again'));
        $this->assertSame(3, SatellitePolygon::withoutGlobalScopes()->count());
        Http::assertSentCount(3 + 3 + 3 + 1 + 1);   // 3 polygons, 3 searches, 3 stats, 1 picture; then one search, nothing new
    }

    // ── settings ─────────────────────────────────────────────────────────────────────────────

    public function test_settings_store_credentials_encrypted_and_check_the_connection(): void
    {
        $token = true;
        Http::fake(['identity.dataspace.copernicus.eu/*' => function () use (&$token) {
            return $token ? Http::response(['access_token' => 'tok-ok', 'expires_in' => 600]) : Http::response(['error' => 'unauthorized_client', 'error_description' => 'Invalid client secret'], 401);
        }, 'api.agromonitoring.com/*' => Http::response(['cod' => 401, 'message' => 'Invalid API key'], 401)]);

        $this->actingAs($this->worker)->get(route('agri.satellite.settings'))->assertForbidden();
        $this->actingAs($this->worker)->put(route('agri.satellite.settings.save', 'copernicus'), $this->settingsBody(['client_secret' => 'x']))->assertForbidden();

        $this->actingAs($this->admin)->put(route('agri.satellite.settings.save', 'copernicus'), $this->settingsBody(['active' => true, 'client_id' => 'sh-abc123', 'client_secret' => 'very-secret']))
            ->assertSessionHasNoErrors();
        $row = DB::table('agri_satellite_providers')->where('company_id', $this->company->id)->where('provider', 'copernicus')->first();
        $this->assertStringNotContainsString('very-secret', $row->credentials);
        $this->assertSame(['client_id' => 'sh-abc123', 'client_secret' => 'very-secret'], json_decode(Crypt::decryptString($row->credentials), true));
        $this->assertTrue((bool) $row->is_active);

        // Blank secrets keep what is stored; the page shows only that one is set.
        $this->actingAs($this->admin)->put(route('agri.satellite.settings.save', 'copernicus'), $this->settingsBody(['active' => true, 'max_cloud_pct' => 20]))->assertSessionHasNoErrors();
        $p = SatelliteProvider::withoutGlobalScopes()->where('provider', 'copernicus')->sole();
        $this->assertSame('very-secret', $p->credentials()['client_secret']);
        $this->assertSame(20.0, (float) $p->options()['max_cloud_pct']);
        $page = $this->actingAs($this->admin)->get(route('agri.satellite.settings'))->assertOk()
            ->assertInertia(fn (AssertableInertia $pg) => $pg->component('Modules/Agri/Satellite/Settings')->where('providers.0.has.client_secret', true)->where('providers.0.active', true));
        $this->assertStringNotContainsString('very-secret', $page->getContent());
        $this->assertStringNotContainsString('very-secret', (string) DB::table('activity_log')->where('log_name', 'agri')->pluck('properties')->implode(' '));

        $this->actingAs($this->admin)->post(route('agri.satellite.settings.check', 'copernicus'))->assertSessionHas('success');
        $this->assertTrue($p->fresh()->last_test_ok);
        $token = false;
        $this->actingAs($this->admin)->post(route('agri.satellite.settings.check', 'copernicus'))->assertSessionHas('error');
        $this->assertFalse($p->fresh()->last_test_ok);
        $this->assertStringContainsString('Invalid client secret', $p->fresh()->last_test_message);

        // Switching AgroMonitoring on switches Copernicus off; its check reports the refused key.
        $this->actingAs($this->admin)->put(route('agri.satellite.settings.save', 'agromonitoring'), $this->settingsBody(['active' => true, 'api_key' => 'bad']))->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->is_active);
        $this->actingAs($this->admin)->post(route('agri.satellite.settings.check', 'agromonitoring'))->assertSessionHas('error');
        $this->assertStringContainsString('401', SatelliteProvider::withoutGlobalScopes()->where('provider', 'agromonitoring')->value('last_test_message'));
    }

    // ── isolation and pages ──────────────────────────────────────────────────────────────────

    public function test_tenants_are_isolated(): void
    {
        $other = Company::create(['name' => 'Ander Plaas']);
        $this->entitle($other);
        $role = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'agri test org admin', 'guard_name' => 'web']);
        $otherAdmin = $this->makeUser('admin@ander.test', $role, $other);
        $otherFarm = $this->farm('SOUTH', $other);
        $theirs = $this->asCompany($other, fn () => FarmField::withoutGlobalScopes()->create(['company_id' => $other->id, 'farm_id' => $otherFarm->id, 'code' => 'Z1', 'name' => 'Theirs',
            'land_use_type' => 'field', 'area' => 50, 'area_unit' => 'ha', 'status' => 'active', 'boundary' => $this->square(29.0, -27.0, 0.01)]));
        $this->asCompany($other, fn () => app(SatelliteImagery::class)->configure($other->id, 'copernicus', ['client_id' => 'theirs', 'client_secret' => 'theirs'], [], false, $otherAdmin));

        $this->copernicus();
        $d1 = today()->subDays(4)->toDateString();
        $this->fieldDays = [$d1 => [0.6, 0.4, 0.8, 1000, 0]];
        $this->fakeCopernicus();
        $this->artisan('agri:satellite')->assertSuccessful();   // every tenant

        $this->assertSame(0, FieldImagery::withoutGlobalScopes()->where('field_id', $theirs->id)->count(), 'Its own provider is switched off: nothing fetched for the other organisation.');
        $mine = FieldImagery::withoutGlobalScopes()->where('field_id', $this->f1->id)->where('index', 'ndvi')->sole();
        $this->actingAs($otherAdmin)->get(route('agri.satellite.image', $mine->uuid))->assertNotFound();
        $this->actingAs($otherAdmin)->get(route('agri.farms.ndvi', $this->farmA->uuid))->assertNotFound();
        $this->actingAs($otherAdmin)->get(route('agri.satellite.settings'))->assertOk()
            ->assertInertia(fn (AssertableInertia $pg) => $pg->where('providers.0.client_id_hint', 'theirs…')->where('providers.0.active', false)->where('usage', null));
        $this->assertSame(['theirs'], $this->asCompany($other, fn () => SatelliteProvider::query()->get()->map(fn ($p) => $p->credentials()['client_id'])->all()));
        $this->assertSame(0, SatelliteUsage::withoutGlobalScopes()->where('company_id', $other->id)->count());
    }

    public function test_pages_render_and_fetch_now_respects_permissions(): void
    {
        $this->copernicus();
        $d1 = today()->subDays(7)->toDateString();
        $this->fieldDays = [$d1 => [0.6, 0.4, 0.8, 1000, 0]];
        $this->zoneDays = ['East' => [$d1 => [0.65, 0.5, 0.8, 500, 0]], 'West' => [$d1 => [0.55, 0.4, 0.7, 500, 0]]];
        $this->fakeCopernicus();

        $this->actingAs($this->outsider)->post(route('agri.satellite.fetch'), ['farm_id' => $this->farmA->uuid])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.satellite.fetch'), ['farm_id' => $this->farmA->uuid, 'field_id' => $this->f1->uuid])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(1, SatelliteScene::withoutGlobalScopes()->count());

        $this->actingAs($this->worker)->get(route('agri.satellite.index', ['farm' => $this->farmA->uuid]))->assertOk()
            ->assertInertia(fn (AssertableInertia $pg) => $pg->component('Modules/Agri/Satellite/Index')
                ->where('health.0.code', 'L1')->where('series.field.code', 'L1')->has('series.points', 1)->has('series.zones', 2)
                ->where('provider.key', 'copernicus')->where('can.configure', false));
        $json = $this->actingAs($this->worker)->getJson(route('agri.farms.ndvi', $this->farmA->uuid))->assertOk()->json();
        $this->assertCount(1, $json['layers']);
        $this->assertSame($d1, $json['layers'][0]['date']);
        $this->assertCount(4, $json['layers'][0]['bounds']);
        $this->assertArrayHasKey($this->f1->uuid, $json['series']);
        $img = $this->actingAs($this->worker)->get($json['layers'][0]['url'])->assertOk();
        $this->assertSame('image/png', $img->headers->get('Content-Type'));
        $this->actingAs($this->manager)->get(route('agri.farms.map', $this->farmA->uuid))->assertOk();
        $this->actingAs($this->admin)->get(route('agri.satellite.settings'))->assertOk()->assertInertia(fn (AssertableInertia $pg) => $pg->component('Modules/Agri/Satellite/Settings'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function square(float $lng, float $lat, float $size): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat - $size], [$lng, $lat - $size], [$lng, $lat]]]];
    }

    private function mappedField(Farm $farm, string $code, float $lng, float $lat): FarmField
    {
        $f = $this->field($farm, $code);
        $this->asCompany($this->company, function () use ($f, $farm, $code, $lng, $lat) {
            $f->update(['boundary' => $this->square($lng, $lat, 0.01)]);
            CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'field_id' => $f->id, 'season_id' => $this->seasonId(),
                'crop_type_id' => $this->cropTypeId(), 'code' => "MZ-{$code}", 'planned_area' => 10, 'status' => 'planted',
                'planned_planting_on' => today()->subMonth(), 'planned_harvest_on' => today()->addMonths(3)]);
        });

        return $f->fresh();
    }

    private function settingsBody(array $o = []): array
    {
        return $o + ['active' => false, 'max_cloud_pct' => 30, 'daily_budget' => 200, 'lookback_days' => 30, 'require_plan' => true, 'zone_stats' => true, 'images' => true];
    }

    private function configure(string $provider, array $creds, array $settings = []): void
    {
        $this->asCompany($this->company, fn () => app(SatelliteImagery::class)->configure($this->company->id, $provider, $creds,
            $settings + ['lookback_days' => 30], true, $this->admin));
    }

    private function copernicus(array $settings = []): void
    {
        $this->configure('copernicus', ['client_id' => 'cdse-client', 'client_secret' => 'cdse-secret'], $settings);
    }

    private function fakeCopernicus(): void
    {
        $png = $this->png();
        $zoneOf = fn (array $g) => collect($this->zones)->search(fn ($z) => $z->boundary == $g);
        $stats = function (array $days) {
            return ['status' => 'OK', 'data' => collect($days)->map(fn ($v, $date) => ['interval' => ['from' => "{$date}T00:00:00Z", 'to' => \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString().'T00:00:00Z'],
                'outputs' => collect(['ndvi' => 0, 'ndre' => -0.15, 'ndwi' => -0.3])->map(fn ($off, $ix) => ['bands' => ['B0' => ['stats' => [
                    'min' => $v[1] + $off, 'max' => $v[2] + $off, 'mean' => $v[0] + $off, 'stDev' => 0.05, 'sampleCount' => $v[3], 'noDataCount' => $v[4]]]]])->all()])->values()->all()];
        };
        Http::fake(function (Request $r) use ($png, $zoneOf, $stats) {
            $u = $r->url();
            if (str_contains($u, 'identity.dataspace.copernicus.eu')) {
                return Http::response(['access_token' => 'tok-1', 'expires_in' => 600, 'token_type' => 'Bearer']);
            }
            if (str_contains($u, '/api/v1/statistics')) {
                if ($this->failStats) {
                    return Http::response('Service unavailable', 503);
                }
                $g = $r['input']['bounds']['geometry'];
                $zone = $zoneOf($g);
                $from = substr($r['aggregation']['timeRange']['from'], 0, 10);
                $to = substr($r['aggregation']['timeRange']['to'], 0, 10);
                $days = collect($zone !== false ? ($this->zoneDays[$zone] ?? []) : $this->fieldDays)->filter(fn ($v, $d) => $d >= $from && $d <= $to)->all();

                return Http::response($stats($days));
            }
            if (str_contains($u, '/api/v1/process')) {
                return Http::response($png, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('unexpected '.$u, 500);
        });
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 150, 60));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }
}
