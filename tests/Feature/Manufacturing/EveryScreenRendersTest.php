<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\Shutdown;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;

/**
 * Each of the module's screens, loaded with realistic data by the role that uses it.
 *
 * An empty tenant hides payload mistakes -- a null relation, a missing eager load, a field named
 * differently from the one the page reads. These run every screen against a populated plant and
 * assert both that it answers and that it names the component the front end will resolve.
 *
 * PageResolvesTest separately proves each of those component files exists.
 */
class EveryScreenRendersTest extends ManufacturingTestCase
{
    private function page(\App\Core\User\Models\User $user, string $url): array
    {
        return $this->actingAs($user)->get($url)->assertOk()->viewData('page');
    }

    private function populate(): array
    {
        $factory = $this->factory('JHB1');

        $this->asCompany($this->company, function () use ($factory) {
            $line = $factory->productionLines()->create([
                'company_id' => $this->company->id, 'code' => 'L1', 'name' => 'Line 1', 'is_active' => true,
            ]);
            $factory->departments()->create([
                'company_id' => $this->company->id, 'code' => 'EXT', 'name' => 'Extrusion', 'is_active' => true,
            ]);

            $centre = WorkCentre::create([
                'company_id' => $this->company->id, 'factory_id' => $factory->id,
                'production_line_id' => $line->id, 'code' => 'PRESS', 'name' => 'Press',
                'capacity_per_hour' => 60, 'cost_per_hour' => 220, 'is_active' => true,
            ]);
            $centre->workstations()->create([
                'company_id' => $this->company->id, 'code' => 'WS1', 'name' => 'Station 1', 'is_active' => true,
            ]);

            Shift::create([
                'company_id' => $this->company->id, 'factory_id' => $factory->id, 'code' => 'DAY',
                'name' => 'Day', 'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30,
                'days_of_week' => [1, 2, 3, 4, 5], 'overtime_multiplier' => 1.5, 'is_active' => true,
            ]);

            Shutdown::create([
                'company_id' => $this->company->id, 'factory_id' => $factory->id,
                'reason' => 'Annual maintenance', 'kind' => 'PLANNED',
                'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(9),
            ]);
        });

        $product = $this->product('FRAME');
        $bolt = $this->material('BOLT');
        $plate = $this->material('PLATE');

        $v1 = $this->approvedBom($product, [[$bolt, 8, 2, 'ea'], [$plate, 1.5, 0, 'kg']], 1);
        $v2 = $this->draftBom($product, [[$bolt, 10, 2, 'ea'], [$plate, 1.5, 0, 'kg']], 1);

        return compact('factory', 'product', 'bolt', 'v1', 'v2');
    }

    public function test_the_dashboard_reports_readiness_and_what_is_missing(): void
    {
        $f = $this->populate();
        // A manufactured product with no approved recipe: the thing the dashboard exists to flag.
        $this->product('UNRECIPED');

        $page = $this->page($this->configurator, route('manufacturing.dashboard'));

        $this->assertSame('Modules/Manufacturing/Dashboard', $page['component']);

        $r = $page['props']['readiness'];
        $this->assertSame(1, $r['factories']);
        $this->assertSame(1, $r['work_centres']);
        $this->assertSame(1, $r['work_centres_rated']);
        $this->assertSame(1, $r['boms_approved']);
        $this->assertSame(1, $r['boms_draft']);

        $this->assertContains('UNRECIPED', array_column($page['props']['products_without_bom'], 'code'));
        $this->assertNotContains('FRAME', array_column($page['props']['products_without_bom'], 'code'));
        $this->assertSame('Annual maintenance', $page['props']['open_shutdowns'][0]['reason']);
        $this->assertTrue($page['props']['open_shutdowns'][0]['whole_factory']);
    }

    public function test_the_engineering_screens_render_for_a_planner(): void
    {
        $f = $this->populate();

        $products = $this->page($this->planner, route('manufacturing.engineering.products.index'));
        $this->assertSame('Modules/Manufacturing/Engineering/Products', $products['component']);
        $this->assertContains('FRAME', array_column($products['props']['items']['data'], 'code'));

        $show = $this->page($this->planner, route('manufacturing.engineering.products.show', $f['product']->uuid));
        $this->assertSame('Modules/Manufacturing/Engineering/ProductShow', $show['component']);
        $this->assertCount(2, $show['props']['item']['boms']);

        $boms = $this->page($this->planner, route('manufacturing.engineering.boms.index'));
        $this->assertSame('Modules/Manufacturing/Engineering/Boms', $boms['component']);
        $this->assertCount(2, $boms['props']['boms']['data']);

        $bom = $this->page($this->planner, route('manufacturing.engineering.boms.show', $f['v1']->uuid));
        $this->assertSame('Modules/Manufacturing/Engineering/BomShow', $bom['component']);
        $this->assertCount(2, $bom['props']['bom']['lines']);
        // The issue quantity includes scrap: 8 at 2% scrap is 8 / 0.98.
        $this->assertEqualsWithDelta(8.163265, $bom['props']['bom']['lines'][0]['gross_quantity_per'], 0.0001);

        $explode = $this->page($this->planner, route('manufacturing.engineering.boms.explode', $f['v1']->uuid).'?quantity=5');
        $this->assertSame('Modules/Manufacturing/Engineering/BomExplode', $explode['component']);
        $this->assertNull($explode['props']['error']);

        $compare = $this->page($this->planner,
            route('manufacturing.engineering.boms.compare', [$f['v1']->uuid, $f['v2']->uuid]));
        $this->assertSame('Modules/Manufacturing/Engineering/BomCompare', $compare['component']);
        $this->assertCount(1, $compare['props']['diff']['changed']);
        $this->assertSame(1, $compare['props']['diff']['unchanged']);

        $used = $this->page($this->planner,
            route('manufacturing.engineering.where-used.index').'?stock_item_id='.$f['bolt']->id);
        $this->assertSame('Modules/Manufacturing/Engineering/WhereUsed', $used['component']);
        $this->assertCount(2, $used['props']['usages']);   // the approved revision and the draft

        $this->page($this->planner, route('manufacturing.engineering.where-used.index'));  // nothing chosen
    }

    public function test_the_setup_screens_render_for_a_configurator(): void
    {
        $this->populate();

        $expected = [
            'manufacturing.setup.factories' => 'Modules/Manufacturing/Setup/Factories',
            'manufacturing.setup.work-centres' => 'Modules/Manufacturing/Setup/WorkCentres',
            'manufacturing.setup.shifts' => 'Modules/Manufacturing/Setup/Shifts',
            'manufacturing.setup.units' => 'Modules/Manufacturing/Setup/Units',
            'manufacturing.setup.numbering' => 'Modules/Manufacturing/Setup/Numbering',
            'manufacturing.setup.policies' => 'Modules/Manufacturing/Setup/Policies',
        ];

        foreach ($expected as $routeName => $component) {
            $page = $this->page($this->configurator, route($routeName));
            $this->assertSame($component, $page['component'], $routeName);
        }

        $centres = $this->page($this->configurator, route('manufacturing.setup.work-centres'))['props']['work_centres'];
        $this->assertTrue($centres[0]['is_rated']);
        $this->assertCount(1, $centres[0]['workstations']);

        // The line and department option lists key on the factory's uuid -- the same identifier the
        // picker uses -- so the dialog can actually filter by the factory chosen.
        $props = $this->page($this->configurator, route('manufacturing.setup.work-centres'))['props'];
        $factoryIds = array_column($props['factories'], 'id');
        foreach ($props['production_lines'] as $line) {
            $this->assertContains($line['factory_id'], $factoryIds, 'A line carries an identifier no factory option has.');
        }

        $shifts = $this->page($this->configurator, route('manufacturing.setup.shifts'))['props'];
        $this->assertSame(450, $shifts['shifts'][0]['working_minutes']);
        $this->assertSame('06:00', $shifts['shifts'][0]['starts_at']);

        $numbering = $this->page($this->configurator, route('manufacturing.setup.numbering'))['props']['sequences'];
        $this->assertStringStartsWith('PO-'.now()->format('Y').'-', collect($numbering)
            ->firstWhere('document_type', 'PRODUCTION_ORDER')['preview']);
    }

    public function test_the_units_screen_groups_containers_apart_from_measurable_units(): void
    {
        $units = $this->page($this->configurator, route('manufacturing.setup.units'))['props']['units'];

        $containers = collect($units)->where('dimension', 'CONTAINER')->pluck('code')->sort()->values()->all();
        $this->assertSame(['pack', 'pallet', 'roll'], $containers);
        $this->assertSame([], collect($units)->where('dimension', 'CONTAINER')->where('is_base', true)->all());
    }

    public function test_the_products_list_filters_by_type_and_by_missing_recipe(): void
    {
        $this->populate();
        $this->product('NO-RECIPE');
        $this->product('BOUGHT', 'RAW_MATERIAL');

        $codes = fn (string $qs) => array_column(
            $this->page($this->planner, route('manufacturing.engineering.products.index').$qs)['props']['items']['data'],
            'code'
        );

        $this->assertSame(['BOUGHT'], $codes('?kind=RAW_MATERIAL'));
        $this->assertSame(['NO-RECIPE'], $codes('?without_bom=1'));
        $this->assertEqualsCanonicalizing(['FRAME', 'NO-RECIPE', 'BOUGHT'], $codes(''));
        $this->assertSame(['FRAME'], $codes('?search=FRA'));
    }
}
