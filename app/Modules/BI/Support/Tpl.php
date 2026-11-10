<?php

namespace App\Modules\BI\Support;

/**
 * Short-hand for writing templates. Positions are on a 12-column grid, rows are 30px.
 *
 *   Tpl::kpi('Revenue', 'fin_sales', ['revenue', 'growth_pct'], 0)            // a KPI card in the top row
 *   Tpl::w('line', 'Revenue by month', 'fin_sales', ['measures' => ['revenue'], 'grain' => 'month'], 0, 4, 6, 8)
 */
final class Tpl
{
    /** A KPI-family card: kpi_card, kpi_sparkline, variance_card, gauge, progress, traffic_light, multi_kpi. */
    public static function kpi(string $title, string $dataset, array $measures, int $x, int $y = 0, string $type = 'kpi_card', string $period = 'ytd', int $w = 3, int $h = 4): array
    {
        return ['type' => $type, 'title' => $title, 'config' => ['dataset' => $dataset, 'measures' => $measures, 'period' => $period], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    /** Any other visual. $config takes measures, dimension, series, grain, period, top, sort, other. */
    public static function w(string $type, string $title, string $dataset, array $config, int $x, int $y, int $w = 6, int $h = 8): array
    {
        return ['type' => $type, 'title' => $title, 'config' => ['dataset' => $dataset] + $config + ['period' => 'ytd'], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    /** A free-text note (no data). */
    public static function text(string $title, string $text, int $x, int $y, int $w = 12, int $h = 3): array
    {
        return ['type' => 'text_box', 'title' => $title, 'config' => ['text' => $text], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }
}
