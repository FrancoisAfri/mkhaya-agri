<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\EnvironmentRecord;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Agri\Models\WeatherReading;
use App\Modules\Agri\Models\WeatherRule;
use App\Modules\Agri\Services\EnvironmentReport;
use App\Modules\Agri\Services\FarmAlerts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Phase 2 §11: weather from the approved provider, kept with its source, fetch time and period;
 * alerts only from approved, activity-specific thresholds where something planned is exposed; and
 * environmental records with a sustainability report that labels estimates and claims no carbon
 * reduction without evidence.
 */
class WeatherEnvironmentTest extends AgriTestCase
{
    private array $forecast = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        config(['agri.weather.provider' => 'open_meteo']);
        // One stub that answers with whatever forecast the test last set (a second Http::fake would not replace the first).
        Http::fake(['api.open-meteo.com/*' => fn () => Http::response($this->forecast)]);
    }

    /** 48 hours of Open-Meteo forecast: calm, except a windy spell tomorrow 09:00–12:00 UTC, and a cold night. */
    private function fakeForecast(int $gust = 38, float $coldest = 3.0): void
    {
        $start = now()->utc()->startOfHour();
        $time = $temp = $hum = $rain = $prob = $wind = $gusts = [];
        for ($i = 0; $i < 48; $i++) {
            $t = $start->copy()->addHours($i);
            $time[] = $t->format('Y-m-d\TH:i');
            $windy = $t->isTomorrow() && $t->hour >= 9 && $t->hour < 12;
            $temp[] = $t->hour === 3 ? $coldest : 18.0;
            $hum[] = 60;
            $rain[] = 0.0;
            $prob[] = 10;
            $wind[] = $windy ? 24.0 : 8.0;
            $gusts[] = $windy ? $gust : 12;
        }
        $this->forecast = (['current' => ['time' => $start->format('Y-m-d\TH:i'), 'interval' => 900, 'temperature_2m' => 17.5,
            'relative_humidity_2m' => 58, 'wind_speed_10m' => 7.0, 'wind_gusts_10m' => 11.0, 'precipitation' => 0.0],
            'hourly' => ['time' => $time, 'temperature_2m' => $temp, 'relative_humidity_2m' => $hum, 'precipitation' => $rain, 'precipitation_probability' => $prob,
                'wind_speed_10m' => $wind, 'wind_gusts_10m' => $gusts]]);
    }

    public function test_11_1_and_11_2_weather_is_kept_with_its_source_and_only_approved_thresholds_meeting_planned_work_raise_alerts(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->asCompany($this->company, fn () => $farm->update(['latitude' => -26.1, 'longitude' => 28.0]));
        $this->fakeForecast();

        $this->actingAs($this->manager)->post(route('agri.weather.refresh'), ['farm_id' => $farm->uuid])->assertSessionHasNoErrors();
        $this->assertSame(48, WeatherReading::withoutGlobalScopes()->where('kind', 'forecast')->count());
        $r = WeatherReading::withoutGlobalScopes()->where('kind', 'forecast')->first();
        $this->assertSame('open_meteo', $r->provider);
        $this->assertNotNull($r->fetched_at);
        $this->actingAs($this->manager)->get(route('agri.weather', ['tab' => 'weather', 'farm' => $farm->uuid]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('data.source', 'Open-Meteo')->where('data.located', true)->has('data.days')->has('data.period'));

        // Spraying planned tomorrow 08:00–14:00 (farm time is SAST, so 06:00–12:00 UTC).
        $field = $this->field($farm);
        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), ['farm_id' => $farm->uuid, 'category' => 'spraying', 'title' => 'Fungicide on F1', 'priority' => 'normal',
            'field_id' => $field->uuid, 'planned_start' => today()->addDay()->format('Y-m-d').' 08:00', 'planned_end' => today()->addDay()->format('Y-m-d').' 14:00'])->assertSessionHasNoErrors();
        $wo = WO::withoutGlobalScopes()->sole();
        $scan = fn () => $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($this->company->id));
        $scan();
        $this->assertSame(0, FarmAlert::withoutGlobalScopes()->where('kind', 'weather')->count(), 'No approved rule, no weather alert: no threshold is assumed.');

        // A rule for this spraying: a draft does nothing; its writer cannot approve it.
        $this->actingAs($this->manager)->post(route('agri.weather.rules.store'), ['name' => 'Fungicide drift', 'activity' => 'spraying', 'severity' => 'critical',
            'conditions' => [['metric' => 'wind_gust', 'op' => '>', 'value' => 25], ['metric' => 'rain_probability', 'op' => '>=', 'value' => 60]], 'lookahead_hours' => 72,
            'advice' => 'Move the spray to a calm window.', 'basis' => 'Product label: do not spray above 25 km/h gusts'])->assertSessionHasNoErrors();
        $rule = WeatherRule::withoutGlobalScopes()->sole();
        $scan();
        $this->assertSame(0, FarmAlert::withoutGlobalScopes()->where('kind', 'weather')->count());
        $this->actingAs($this->manager)->post(route('agri.weather.rules.approve', $rule->uuid))->assertSessionHasErrors('rule');
        $this->actingAs($this->admin)->post(route('agri.weather.rules.approve', $rule->uuid))->assertSessionHasNoErrors();
        $alert = FarmAlert::withoutGlobalScopes()->where('kind', 'weather')->sole();
        $this->assertStringContainsString($wo->reference, $alert->title);
        $this->assertStringContainsString('gusts 38', $alert->title);
        $this->assertStringContainsString('Fungicide drift', $alert->message);
        $this->assertStringContainsString('Product label', $alert->message);
        $this->assertStringContainsString('Open-Meteo', $alert->message);
        $this->assertSame('critical', $alert->severity);

        // A frost rule for a different crop raises nothing; for the crop growing here it does.
        $plan = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'field_id' => $field->id,
            'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ-1', 'planned_area' => 10, 'status' => 'planted',
            'planned_planting_on' => today()->subMonth(), 'planned_harvest_on' => today()->addMonths(3)]));
        $other = $this->asCompany($this->company, fn () => \App\Modules\Agri\Models\CropType::withoutGlobalScopes()->where('id', '!=', $plan->crop_type_id)->value('id'));
        foreach ([[$other, 'Frost: other crop'], [$plan->crop_type_id, 'Frost: maize']] as [$crop, $name]) {
            $this->actingAs($this->manager)->post(route('agri.weather.rules.store'), ['name' => $name, 'activity' => 'frost', 'severity' => 'warning', 'crop_type_id' => $crop,
                'conditions' => [['metric' => 'temperature', 'op' => '<=', 'value' => 4]], 'lookahead_hours' => 48, 'advice' => 'Run the frost fans.', 'basis' => 'Agronomist'])->assertSessionHasNoErrors();
            $this->actingAs($this->admin)->post(route('agri.weather.rules.approve', WeatherRule::withoutGlobalScopes()->latest('id')->first()->uuid))->assertSessionHasNoErrors();
        }
        $frost = FarmAlert::withoutGlobalScopes()->where('kind', 'weather')->where('title', 'like', 'Frost%')->get();
        $this->assertCount(1, $frost);
        $this->assertStringContainsString('MZ-1', $frost->first()->title);

        // The wind drops in the next forecast: the spraying alert clears.
        $this->fakeForecast(gust: 15, coldest: 10);
        $this->actingAs($this->manager)->post(route('agri.weather.refresh'), ['farm_id' => $farm->uuid])->assertSessionHasNoErrors();
        $this->assertSame(FarmAlert::RESOLVED, $alert->fresh()->status);
        $this->assertSame(0, FarmAlert::withoutGlobalScopes()->where('kind', 'weather')->where('status', FarmAlert::OPEN)->count());

        $this->actingAs($this->manager)->get(route('agri.weather', ['tab' => 'rules', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_11_3_environmental_records_feed_a_sustainability_report_that_labels_estimates_and_claims_no_unverified_reduction(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $tractor = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm, ['code' => 'T1', 'name' => 'Tractor', 'machine_type' => 'tractor', 'hours_reading' => 10]));
        $this->asCompany($this->company, fn () => MachineUsageLog::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'machine_id' => $tractor->id,
            'used_on' => today(), 'hours' => 5, 'fuel_litres' => 100]));
        $post = fn (array $d) => $this->actingAs($this->manager)->post(route('agri.environment.store'), $d + ['farm_id' => $farm->uuid, 'recorded_on' => today()->toDateString()]);
        $post(['kind' => 'water', 'quantity' => 250, 'unit' => 'm3', 'method' => 'Borehole meter', 'description' => 'March meter reading'])->assertSessionHasNoErrors();
        $post(['kind' => 'container_disposal', 'quantity' => 40, 'unit' => 'containers', 'description' => 'To the CropLife collection point',
            'details' => ['count' => 40, 'triple_rinsed' => 38, 'collector' => 'CropLife SA']])->assertSessionHasNoErrors();
        $post(['kind' => 'rehabilitation', 'quantity' => 2.5, 'unit' => 'ha', 'description' => 'Riparian strip replanted'])->assertSessionHasNoErrors();

        // A carbon project is "verified" only with methodology, verifier and evidence.
        $post(['kind' => 'carbon_project', 'quantity' => 120, 'unit' => 't CO2e', 'description' => 'No-till conversion', 'verification' => 'verified'])->assertSessionHasErrors('verification');
        $post(['kind' => 'carbon_project', 'quantity' => 120, 'unit' => 't CO2e', 'description' => 'No-till conversion'])->assertSessionHasNoErrors();
        $post(['kind' => 'carbon_project', 'quantity' => 80, 'unit' => 't CO2e', 'description' => 'Agroforestry block', 'verification' => 'verified', 'methodology' => 'Verra VM0042 v2.0',
            'verifier' => 'Accredited VVB', 'evidence' => UploadedFile::fake()->createWithContent('verification.pdf', "%PDF-1.4\n% verification statement\n")])->assertSessionHasNoErrors();

        // Diesel drawn from the stores onto a field job is fuel, not an application.
        $this->openBooks();
        $diesel = $this->stockItem('DSL', 'l', 21);
        $map = $this->stockItem('MAP', 'kg', 14);
        $this->asCompany($this->company, function () use ($farm, $diesel, $map) {
            \App\Modules\Agri\Models\AgriItemSetting::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'stock_item_id' => $diesel->id, 'category' => 'fuel']);
            \App\Modules\Agri\Models\AgriItemSetting::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'stock_item_id' => $map->id, 'category' => 'fertiliser']);
            $job = \App\Modules\Agri\Models\FieldActivity::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
                'activity_type' => 'fertilising', 'title' => 'Plant and fertilise', 'planned_on' => today()]);
            foreach ([[$diesel, 50, 'l'], [$map, 900, 'kg']] as [$item, $qty, $uom]) {
                \App\Modules\Agri\Models\ActivityInput::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'activity_id' => $job->id,
                    'stock_item_id' => $item->id, 'quantity' => $qty, 'uom' => $uom, 'status' => \App\Modules\Agri\Models\ActivityInput::ISSUED, 'issued_at' => now()]);
            }
        });

        $r = $this->asCompany($this->company, fn () => app(EnvironmentReport::class)->summary($farm, today()->startOfYear()->toDateString(), today()->toDateString()));
        $this->assertEquals(250, $r['water_m3']);
        $this->assertEquals(150, $r['diesel_l'], 'Machine fuel plus diesel issued onto a field job.');
        $this->assertSame(['fertiliser'], array_column($r['applications'], 'category'), 'Fuel is not a field application.');
        $this->assertEquals(402.0, $r['emissions']['lines'][0]['kg_co2e']);
        $this->assertStringContainsString('IPCC', $r['emissions']['lines'][0]['source']);
        $this->assertNull(collect($r['emissions']['lines'])->firstWhere('what', 'Electricity 0 kWh')['kg_co2e'], 'No grid factor set: not estimated.');
        $this->assertStringContainsString('Not a certified', $r['emissions']['label']);
        $this->assertSame(1, $r['carbon']['unverified']);
        $this->assertCount(1, $r['carbon']['verified']);
        $this->assertSame('Verra VM0042 v2.0', $r['carbon']['verified'][0]['methodology']);
        $this->assertSame(38, $r['containers']['triple_rinsed']);
        $this->assertEquals(2.5, $r['rehabilitated_ha']);

        $rec = EnvironmentRecord::withoutGlobalScopes()->where('verification', 'verified')->sole();
        $this->actingAs($this->manager)->get(route('agri.environment.evidence', $rec->uuid))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.weather', ['tab' => 'sustainability', 'farm' => $farm->uuid]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.weather', ['tab' => 'environment', 'farm' => $farm->uuid]))->assertOk();
    }
}
