<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\AgriOrgSetting;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\PeerBenchmark;
use App\Modules\Agri\Services\AgriCatalogue;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

/**
 * Peer benchmarks across organisations: opt-in to contribute and to see, k-anonymity (5 farms from
 * 3 organisations), aggregates only in a table with no company column, and no organisation able to
 * read another's raw rows.
 */
class PeerBenchmarksTest extends AgriTestCase
{
    /** @var array<string, Company> */
    private array $orgs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgs['A'] = $this->company;
        foreach (['B', 'C', 'E'] as $k) {
            $this->orgs[$k] = Company::create(['name' => "Peer {$k} Boerdery"]);
            $this->entitle($this->orgs[$k]);
        }
        foreach ($this->orgs as $c) {
            $this->asCompany($c, fn () => app(AgriCatalogue::class)->ensureDefaults($c->id));
        }
        // Maize, summer 2025/26, all in the same 1° square: A 6 and 8 t/ha, B 7 and 9, C 10 — and E,
        // which has not opted in, with yields far off the band.
        foreach ([['A', 'A1', 6, 10000], ['A', 'A2', 8, 12000], ['B', 'B1', 7, 14000], ['B', 'B2', 9, 16000], ['C', 'C1', 10, 18000],
            ['E', 'E1', 1, 1000], ['E', 'E2', 30, 1000], ['E', 'E3', 40, 1000]] as [$org, $code, $tPerHa, $labour]) {
            $this->crop($this->orgs[$org], $code, 'MAIZE-W', $tPerHa, $labour);
        }
        // Soybean on five farms — but from only two organisations.
        foreach ([['A', 'A1'], ['A', 'A2'], ['B', 'B1'], ['B', 'B2'], ['B', 'B3']] as [$org, $code]) {
            $this->crop($this->orgs[$org], $code, 'SOY', 3, 5000);
        }
        foreach (['B', 'C'] as $k) {
            $this->asCompany($this->orgs[$k], fn () => AgriOrgSetting::for($this->orgs[$k]->id)->update(['benchmarks_opt_in' => true]));
        }
    }

    /** A completed 10 ha crop on the organisation's farm $code, with its harvest and a labour cost. */
    private function crop(Company $c, string $code, string $crop, float $tPerHa, float $labour): void
    {
        $farm = Farm::withoutGlobalScopes()->where('company_id', $c->id)->where('code', $code)->first() ?? $this->farm($code, $c);
        $this->asCompany($c, function () use ($c, $farm, $code, $crop, $tPerHa, $labour) {
            $farm->forceFill(['latitude' => -26.4, 'longitude' => 28.3])->save();
            $field = FarmField::withoutGlobalScopes()->firstOrCreate(['farm_id' => $farm->id, 'code' => "{$code}-{$crop}"],
                ['company_id' => $c->id, 'name' => "Land {$code}", 'land_use_type' => 'field', 'area' => 10, 'area_unit' => 'ha', 'status' => 'active']);
            $season = CropSeason::withoutGlobalScopes()->where('company_id', $c->id)->orderBy('starts_on')->value('id');
            $plan = CropPlan::withoutGlobalScopes()->create(['company_id' => $c->id, 'farm_id' => $farm->id, 'field_id' => $field->id, 'season_id' => $season,
                'crop_type_id' => CropType::withoutGlobalScopes()->where('company_id', $c->id)->where('code', $crop)->value('id'), 'code' => "{$code}-{$crop}-25",
                'planned_area' => 10, 'area_unit' => 'ha', 'planned_planting_on' => '2025-11-01', 'planted_on' => '2025-11-01', 'planned_harvest_on' => '2026-05-15',
                'harvest_completed_on' => '2026-05-20', 'status' => CropPlan::COMPLETED, 'yield_unit' => 't']);
            HarvestRecord::withoutGlobalScopes()->create(['company_id' => $c->id, 'farm_id' => $farm->id, 'crop_plan_id' => $plan->id, 'field_id' => $field->id,
                'harvested_on' => '2026-05-18', 'quantity' => $tPerHa * 10, 'unit' => 't', 'destination' => 'own_use']);
            FieldActivity::withoutGlobalScopes()->create(['company_id' => $c->id, 'farm_id' => $farm->id, 'field_id' => $field->id, 'crop_plan_id' => $plan->id,
                'activity_type' => 'harvesting', 'title' => 'Harvest', 'planned_on' => '2026-05-18', 'status' => FieldActivity::COMPLETED, 'completed_at' => '2026-05-18 12:00:00',
                'labour_cost' => $labour]);
        });
    }

    private function band(string $crop, string $metric): ?PeerBenchmark
    {
        return PeerBenchmark::query()->where('crop_code', $crop)->where('metric', $metric)->first();
    }

    public function test_opted_in_organisations_make_k_anonymous_bands_of_aggregates_only(): void
    {
        $this->actingAs($this->worker)->post(route('agri.benchmarks.opt-in'), ['opt_in' => true])->assertForbidden();
        $this->actingAs($this->admin)->post(route('agri.benchmarks.opt-in'), ['opt_in' => true])->assertSessionHasNoErrors();
        $this->assertTrue(AgriOrgSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->value('benchmarks_opt_in'));

        $this->artisan('agri:benchmarks')->assertSuccessful();

        $y = $this->band('MAIZE-W', 'yield_t_ha');
        $this->assertNotNull($y);
        $this->assertSame('S2025/26', $y->season_key);
        $this->assertSame('grid:-27:28', $y->region_key);
        $this->assertSame(5, $y->farms, 'E did not opt in: its three farms are not counted.');
        $this->assertSame(3, $y->organisations);
        $this->assertEqualsWithDelta(7.0, $y->p25, 0.001);
        $this->assertEqualsWithDelta(8.0, $y->median, 0.001);
        $this->assertEqualsWithDelta(9.0, $y->p75, 0.001);
        $cost = $this->band('MAIZE-W', 'cost_per_ha');
        $this->assertEqualsWithDelta(1400.0, $cost->median, 0.001, 'Labour 10 000–18 000 on 10 ha.');
        $this->assertSame('ZAR', $cost->currency);
        $this->assertNotNull($this->band('MAIZE-W', 'cost_per_t'));

        // Five soybean farms, but only two organisations: nothing is written.
        $this->assertNull($this->band('SOY', 'yield_t_ha'));

        // The table holds aggregates only.
        $cols = Schema::getColumnListing('agri_benchmarks');
        foreach (['company_id', 'farm_id', 'field_id', 'crop_plan_id'] as $c) {
            $this->assertNotContains($c, $cols);
        }

        // The page shows each crop against its band.
        $this->actingAs($this->admin)->get(route('agri.benchmarks.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Modules/Agri/Benchmarks')->where('data.opted_in', true)
                ->where('data.rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['plan'] === 'A1-MAIZE-W-25' && (float) $r['bands']['yield_t_ha']['median'] === 8.0
                    && (float) $r['own']['yield_t_ha'] === 6.0))
                ->where('data.rows', fn ($rows) => collect($rows)->every(fn ($r) => str_starts_with($r['plan'], 'A')), ));
    }

    public function test_opting_out_hides_the_bands_and_removes_the_contribution(): void
    {
        $this->asCompany($this->company, fn () => AgriOrgSetting::for($this->company->id)->update(['benchmarks_opt_in' => true]));
        $this->artisan('agri:benchmarks')->assertSuccessful();
        $this->assertNotNull($this->band('MAIZE-W', 'yield_t_ha'));

        $this->actingAs($this->admin)->post(route('agri.benchmarks.opt-in'), ['opt_in' => false])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('agri.benchmarks.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('data.opted_in', false)->where('data.rows', []));

        // Without A, three farms from two organisations remain: below the threshold, so the band goes.
        $this->artisan('agri:benchmarks')->assertSuccessful();
        $this->assertNull($this->band('MAIZE-W', 'yield_t_ha'));
        $this->assertSame(0, PeerBenchmark::query()->count());
    }

    public function test_no_organisation_reads_another_organisations_raw_rows(): void
    {
        foreach ($this->orgs as $c) {
            $this->asCompany($c, fn () => AgriOrgSetting::for($c->id)->update(['benchmarks_opt_in' => true]));
        }
        $this->artisan('agri:benchmarks')->assertSuccessful();
        $b = $this->orgs['B'];
        $theirPlan = CropPlan::withoutGlobalScopes()->where('company_id', $b->id)->value('id');
        $theirHarvest = HarvestRecord::withoutGlobalScopes()->where('company_id', $b->id)->value('id');

        // With A set on the connection, as every request is, row-level security returns none of B's rows —
        // while the aggregates (no organisation in them) stay readable.
        DB::select("SELECT set_config('app.company_id', ?, true)", [(string) $this->company->id]);
        try {
            $this->assertSame(0, DB::table('crop_plans')->where('id', $theirPlan)->count());
            $this->assertSame(0, DB::table('harvest_records')->where('id', $theirHarvest)->count());
            $this->assertSame(0, DB::table('agri_org_settings')->where('company_id', $b->id)->count());
            $this->assertSame(0, DB::table('crop_plans')->where('company_id', '!=', $this->company->id)->count());
            $this->assertGreaterThan(0, DB::table('agri_benchmarks')->count());
        } finally {
            DB::select("SELECT set_config('app.company_id', '', true)");
        }

        // And A's own benchmark page names none of B's plans or farms.
        $json = json_encode($this->actingAs($this->admin)->get(route('agri.benchmarks.index'))->assertOk()->viewData('page')['props']);
        $this->assertStringNotContainsString('B1-', $json);
        $this->assertStringNotContainsString('Farm B1', $json);
        $this->assertStringNotContainsString('Peer B', $json);
    }
}
