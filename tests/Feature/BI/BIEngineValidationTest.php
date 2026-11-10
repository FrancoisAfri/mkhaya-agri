<?php

namespace Tests\Feature\BI;

use App\Modules\BI\Services\BIEngine;
use App\Modules\BI\Support\DatasetRegistry;
use App\Modules\BI\Support\TemplateRegistry;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A saved dashboard is user input. Before anything reaches SQL it must be reduced to keys the
 * dataset files define — so a widget can never smuggle SQL in through a measure, dimension,
 * period or dataset name.
 */
class BIEngineValidationTest extends TestCase
{
    private function engine(): BIEngine
    {
        return app(BIEngine::class);
    }

    public function test_unknown_datasets_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine()->validate(['kind' => 'kpi', 'dataset' => "fin_sales; drop table users"]);
    }

    public function test_unknown_measures_and_dimensions_are_dropped(): void
    {
        $cfg = $this->engine()->validate(['kind' => 'category', 'dataset' => 'fin_sales',
            'measures' => ['revenue', "sum(1); delete from users", 'growth_pct'], 'dimension' => "p.name) or 1=1 --", 'series' => 'nope']);

        $this->assertSame(['revenue', 'growth_pct'], $cfg['measures']);
        $this->assertNull($cfg['dimension']);
        $this->assertNull($cfg['series']);
    }

    public function test_a_widget_with_no_real_measure_gets_the_datasets_first(): void
    {
        $cfg = $this->engine()->validate(['kind' => 'kpi', 'dataset' => 'fin_sales', 'measures' => ['growth_pct']]);

        $this->assertSame(array_key_first(DatasetRegistry::get('fin_sales')['measures']), $cfg['measures'][0]);
    }

    public function test_periods_grains_and_sizes_are_clamped(): void
    {
        $cfg = $this->engine()->validate(['kind' => 'time', 'dataset' => 'fin_sales', 'period' => "'; --", 'grain' => 'century', 'top' => 9999, 'sort' => 'random()']);

        $this->assertSame('dashboard', $cfg['period']);
        $this->assertSame('month', $cfg['grain']);
        $this->assertSame(100, $cfg['top']);
        $this->assertSame('desc', $cfg['sort']);
    }

    public function test_unknown_kinds_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine()->validate(['kind' => 'raw_sql', 'dataset' => 'fin_sales']);
    }

    public function test_a_templates_own_period_is_kept_and_ytd_is_only_the_default(): void
    {
        $w = \App\Modules\BI\Support\Tpl::w('table', 'Register', 'fin_sales', ['period' => 'all', 'dataset' => 'nope'], 0, 0);
        $this->assertSame('all', $w['config']['period']);
        $this->assertSame('fin_sales', $w['config']['dataset']);
        $this->assertSame('ytd', \App\Modules\BI\Support\Tpl::w('table', 'Register', 'fin_sales', [], 0, 0)['config']['period']);
    }

    public function test_every_template_uses_known_visual_types_and_datasets(): void
    {
        $this->assertNotEmpty(TemplateRegistry::all());
        foreach (TemplateRegistry::all() as $key => $t) {
            foreach ($t['widgets'] as $w) {
                $this->assertArrayHasKey($w['type'], BIEngine::TYPE_KIND, "{$key}: unknown type {$w['type']}");
                if (BIEngine::TYPE_KIND[$w['type']] !== 'none') {
                    $this->assertNotNull(DatasetRegistry::get($w['config']['dataset'] ?? ''), "{$key}: unknown dataset");
                }
                $this->assertLessThanOrEqual(12, $w['x'] + $w['w'], "{$key}: widget runs off the grid");
            }
        }
    }
}
