<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropCoefficient;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\IrrigationRecommendation;
use App\Modules\Agri\Models\IrrigationZone;
use App\Modules\Agri\Models\SoilMoistureReading;
use App\Modules\Agri\Models\WaterBalanceDay;
use App\Modules\Agri\Models\WeatherReading;
use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\Agri\Services\WaterBalance;
use App\Modules\Agri\Services\WeatherIntelligence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * Irrigation scheduling from evapotranspiration: ET0 from the provider (or Hargreaves), FAO-56
 * coefficients, the daily root-zone balance per zone, the threshold turning into a recommendation
 * and a planned irrigation, a soil-moisture reading resetting the balance, and the farm alert for a
 * zone past its threshold with nothing planned. Weather is fixed; no network call is made.
 */
class IrrigationSchedulingTest extends AgriTestCase
{
    private Farm $farmA;

    private FarmField $f1;

    private IrrigationZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->catalogue();
        $this->farmA = $this->farm('NORTH');
        $this->farmA->update(['latitude' => -26.0, 'longitude' => 28.0]);
        $this->member($this->manager, $this->farmA);
        $this->member($this->worker, $this->farmA, 'worker');
        $this->f1 = $this->field($this->farmA, 'P1', 50);
        $this->asCompany($this->company, fn () => FarmField::withoutGlobalScopes()->whereKey($this->f1->id)->update(['soil_type' => 'Loam', 'irrigation' => 'centre_pivot']));
        $this->f1->refresh();
        $this->zone = $this->asCompany($this->company, fn () => IrrigationZone::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id,
            'field_id' => $this->f1->id, 'code' => 'PIV', 'name' => 'Pivot', 'area_ha' => 50, 'system' => 'centre_pivot', 'flow_m3_per_hour' => 200, 'is_active' => true]));
        // Maize in the ground, with flat, round coefficients so the arithmetic can be checked by hand:
        // Kc 1.0 all season, full 1.0 m roots, p 0.5 → TAW (loam 31 − 15 %) = 160 mm, RAW = 80 mm at ETc 5.
        $this->asCompany($this->company, function () {
            CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'field_id' => $this->f1->id,
                'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId('MAIZE-W'), 'code' => 'MZ-1', 'planned_area' => 50, 'area_unit' => 'ha',
                'planned_planting_on' => today()->subDays(100), 'planted_on' => today()->subDays(100), 'planned_harvest_on' => today()->addDays(60),
                'status' => CropPlan::PLANTED, 'yield_unit' => 't']);
            app(WaterBalance::class)->ensureCoefficients($this->company->id);
            CropCoefficient::withoutGlobalScopes()->where('crop_type_id', $this->cropTypeId('MAIZE-W'))->update(['kc_ini' => 1, 'kc_mid' => 1, 'kc_end' => 1,
                'stage_ini_pct' => 0, 'stage_dev_pct' => 0, 'stage_mid_pct' => 100, 'stage_late_pct' => 0, 'root_depth_m' => 1.0, 'depletion_p' => 0.5]);
        });
        $this->weather(5.0);
    }

    /** ET0 of $et0 every day, no rain: observed for the past, the forecast's daily rows from today. */
    private function weather(float $et0, array $rain = []): void
    {
        $this->asCompany($this->company, function () use ($et0, $rain) {
            for ($d = -50; $d <= 8; $d++) {
                $day = today()->addDays($d);
                WeatherReading::withoutGlobalScopes()->updateOrCreate(['farm_id' => $this->farmA->id, 'field_id' => null, 'provider' => 'open_meteo',
                    'kind' => $d < 0 ? 'observed' : 'daily', 'period_start' => Carbon::parse($day->toDateString(), 'UTC')],
                    ['company_id' => $this->company->id, 'period_end' => Carbon::parse($day->toDateString(), 'UTC')->addDay(), 'fetched_at' => now(),
                        'temp_min' => 12, 'temp_max' => 28, 'rain_mm' => $rain[$d] ?? 0, 'et0_mm' => $et0, 'et0_source' => 'provider']);
            }
        });
    }

    private function compute(): array
    {
        return $this->asCompany($this->company, fn () => app(WaterBalance::class)->computeFarm($this->farmA->fresh()));
    }

    private function day(int $offset): WaterBalanceDay
    {
        return WaterBalanceDay::withoutGlobalScopes()->where('scope_key', "z:{$this->zone->id}")->where('day', today()->addDays($offset)->toDateString())->sole();
    }

    public function test_balance_arithmetic_threshold_recommendation_and_sensor_reset(): void
    {
        // A probe two days ago reads 26 % (field capacity 31 %): the root zone is 50 mm dry that morning.
        $this->asCompany($this->company, fn () => SoilMoistureReading::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id,
            'field_id' => $this->f1->id, 'zone_id' => $this->zone->id, 'read_at' => today()->subDays(2)->setTime(6, 0), 'vwc_pct' => 26, 'source' => 'probe']));
        // Yesterday 5 000 m³ went on the 50 ha zone: 10 mm gross, 8.5 mm net at 85 % pivot efficiency.
        $this->asCompany($this->company, fn () => IrrigationEvent::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id,
            'field_id' => $this->f1->id, 'zone_id' => $this->zone->id, 'actual_start' => today()->subDay()->setTime(5, 0), 'actual_m3' => 5000, 'status' => 'done']));

        $r = $this->compute();
        $this->assertSame(1, $r['scopes']);

        $before = $this->day(-3);
        $this->assertEqualsWithDelta(160.0, $before->taw_mm, 0.01, 'TAW = (31 − 15) × 10 × 1.0 m.');
        $this->assertEqualsWithDelta(160.0, $before->depletion_mm, 0.01, 'Weeks without water run the root zone dry (capped at TAW).');
        $this->assertEqualsWithDelta(1.0, $before->kc, 0.001);
        $this->assertEqualsWithDelta(5.0, $before->etc_mm, 0.001, 'ETc = ET0 × Kc.');

        $reset = $this->day(-2);
        $this->assertTrue($reset->reset_by_sensor, 'The probe reading replaces the modelled depletion.');
        $this->assertEqualsWithDelta(55.0, $reset->depletion_mm, 0.01, '50 mm from the probe + 5 mm ETc.');
        $this->assertEqualsWithDelta(80.0, $reset->raw_mm, 0.01, 'RAW = 0.5 × 160 at ETc 5.');
        $this->assertEqualsWithDelta(8.5, $this->day(-1)->irrigation_mm, 0.01, 'Net irrigation after pivot losses.');
        $this->assertEqualsWithDelta(51.5, $this->day(-1)->depletion_mm, 0.01, '55 − 8.5 + 5.');
        $this->assertTrue($this->day(0)->is_forecast);
        $this->assertEqualsWithDelta(76.5, $this->day(4)->depletion_mm, 0.01);

        // Day +5 would reach 81.5 mm ≥ 80 mm: refill it — 81.5 mm net, 95.9 mm gross, 47 950 m³ on 50 ha.
        $rec = IrrigationRecommendation::withoutGlobalScopes()->where('status', 'open')->sole();
        $this->assertSame(today()->addDays(5)->toDateString(), $rec->recommend_on->toDateString());
        $this->assertEqualsWithDelta(81.5, $rec->net_mm, 0.01);
        $this->assertEqualsWithDelta(95.9, $rec->gross_mm, 0.01);
        $this->assertEqualsWithDelta(47950.0, $rec->volume_m3, 0.1);
        $this->assertEqualsWithDelta(0.0, $this->day(5)->depletion_mm, 0.01, 'The rest of the week assumes the recommended irrigation is given (back to field capacity).');
        $this->assertEqualsWithDelta(5.0, $this->day(6)->depletion_mm, 0.01);

        // Recomputing does not pile recommendations up.
        $this->compute();
        $this->assertSame(1, IrrigationRecommendation::withoutGlobalScopes()->where('status', 'open')->count());

        // A user turns it into a planned irrigation event.
        $this->actingAs($this->worker)->post(route('agri.crops.irrigation-recommendations.plan', $rec->uuid))->assertSessionHasNoErrors();
        $rec->refresh();
        $this->assertSame('planned', $rec->status);
        $event = IrrigationEvent::withoutGlobalScopes()->findOrFail($rec->event_id);
        $this->assertSame('planned', $event->status);
        $this->assertEqualsWithDelta(47950.0, $event->planned_m3, 0.1);
        $this->assertSame((int) $this->zone->id, (int) $event->zone_id);
        $this->assertSame(today()->addDays(5)->toDateString(), $event->planned_start->toDateString());

        // The planned event now counts in the forecast: no new recommendation for that day.
        $this->compute();
        $this->assertSame(0, IrrigationRecommendation::withoutGlobalScopes()->where('status', 'open')->count());
        $this->assertGreaterThan(0, $this->day(5)->irrigation_mm);

        // Rain: under 2 mm is lost, 80 % of the rest counts.
        $this->assertSame(0.0, WaterBalance::effectiveRain(1.5));
        $this->assertSame(8.0, WaterBalance::effectiveRain(10.0));
    }

    public function test_hargreaves_fills_in_when_the_provider_sends_no_et0(): void
    {
        $et0 = WaterBalance::hargreaves(12, 30, -26.0, 15);   // a mid-January day on the Highveld
        $this->assertGreaterThan(4.5, $et0);
        $this->assertLessThan(8.0, $et0);

        $this->asCompany($this->company, fn () => WeatherReading::withoutGlobalScopes()->where('farm_id', $this->farmA->id)->update(['et0_mm' => null, 'et0_source' => null]));
        $this->compute();
        $row = $this->day(-1);
        $this->assertSame('hargreaves', $row->et0_source);
        $this->assertEqualsWithDelta(WaterBalance::hargreaves(12, 28, -26.0, today()->subDay()->dayOfYear), $row->et0_mm, 0.01);
    }

    public function test_open_meteo_daily_et0_is_fetched_and_kept(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response([
            'hourly' => ['time' => [], 'temperature_2m' => []],
            'daily' => ['time' => [today()->toDateString(), today()->addDay()->toDateString()], 'temperature_2m_max' => [29.1, 31.0], 'temperature_2m_min' => [13.2, 15.0],
                'precipitation_sum' => [0, 4.2], 'et0_fao_evapotranspiration' => [5.61, 6.02]],
        ])]);
        $this->asCompany($this->company, fn () => app(WeatherIntelligence::class)->refresh($this->farmA->fresh()));

        Http::assertSent(fn ($req) => str_contains((string) $req->url(), 'et0_fao_evapotranspiration'));
        $rows = WeatherReading::withoutGlobalScopes()->where('farm_id', $this->farmA->id)->where('kind', 'daily')->where('period_start', '>=', today()->startOfDay())->orderBy('period_start')->get();
        $this->assertEqualsWithDelta(5.61, $rows->first()->et0_mm, 0.001);
        $this->assertSame('provider', $rows->first()->et0_source);
        $this->assertEqualsWithDelta(4.2, $rows->get(1)->rain_mm, 0.001);
    }

    public function test_a_zone_past_its_threshold_with_nothing_planned_raises_an_alert(): void
    {
        $this->compute();     // no probe, no irrigation: the zone has been at TAW for weeks
        $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($this->company->id));
        $alert = FarmAlert::withoutGlobalScopes()->where('kind', 'irrigation_due')->sole();
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString('P1 · PIV', $alert->title);
        $rec = IrrigationRecommendation::withoutGlobalScopes()->where('status', 'open')->orderBy('recommend_on')->first();
        $this->assertSame(today()->toDateString(), $rec->recommend_on->toDateString(), 'Past the threshold: irrigate today.');
        $this->assertStringContainsString('Past the allowed depletion', $rec->reason);

        // Planning irrigation in the next two days clears it.
        $this->actingAs($this->manager)->post(route('agri.crops.irrigation-recommendations.plan', $rec->uuid))->assertSessionHasNoErrors();
        $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($this->company->id));
        $this->assertSame('resolved', $alert->fresh()->status);
    }

    public function test_permissions_and_the_irrigation_screen(): void
    {
        $this->compute();
        $rec = IrrigationRecommendation::withoutGlobalScopes()->where('status', 'open')->first();
        $kc = CropCoefficient::withoutGlobalScopes()->where('crop_type_id', $this->cropTypeId('MAIZE-W'))->sole();

        // Somebody with no farm permissions (and no farm) is refused — not found, as every farm record is.
        $this->assertContains($this->actingAs($this->outsider)->post(route('agri.crops.irrigation-recommendations.plan', $rec->uuid))->status(), [403, 404]);
        $this->assertContains($this->actingAs($this->outsider)->post(route('agri.crops.irrigation-recommendations.dismiss', $rec->uuid))->status(), [403, 404]);
        $this->assertSame('open', $rec->fresh()->status);
        $body = ['kc_ini' => 0.3, 'kc_mid' => 1.2, 'kc_end' => 0.6, 'stage_ini_pct' => 20, 'stage_dev_pct' => 27, 'stage_mid_pct' => 33, 'stage_late_pct' => 20, 'root_depth_m' => 1.2, 'depletion_p' => 0.55];
        $this->actingAs($this->worker)->patch(route('agri.crops.coefficients.update', $kc->uuid), $body)->assertForbidden();
        $this->actingAs($this->manager)->patch(route('agri.crops.coefficients.update', $kc->uuid), ['stage_late_pct' => 10] + $body)->assertSessionHasErrors('stage_ini_pct');
        $this->actingAs($this->manager)->patch(route('agri.crops.coefficients.update', $kc->uuid), $body)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(1.2, $kc->fresh()->kc_mid, 0.001);

        $this->actingAs($this->worker)->post(route('agri.crops.irrigation-recommendations.dismiss', $rec->uuid))->assertSessionHasNoErrors();
        $this->assertSame('dismissed', $rec->fresh()->status);

        $this->actingAs($this->manager)->get(route('agri.crops.precision', ['tab' => 'irrigation', 'farm' => $this->farmA->uuid]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Modules/Agri/Crops/Precision')
                ->has('data.balance.balances', 1)
                ->where('data.balance.balances.0.label', 'P1 · PIV')
                ->has('data.balance.balances.0.points')
                ->has('data.balance.schedule')
                ->has('data.balance.coefficients'));
    }
}
