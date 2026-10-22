<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\GradingStandard;

/**
 * Grain grades and the deductions a load takes before it is settled, kept on the organisation's
 * grading standards (one per grain crop) so a farm can change them without a release.
 *
 * The starting table follows the South African grading regulations for maize (WM1–WM3 / YM1–YM3),
 * bread wheat (B1–B4, utility), soybeans and sunflower seed in outline; it is a starting point to
 * check against the current regulations and the buyer's own tables, and says so.
 *
 * Deductions, on the net mass weighed:
 *  - moisture shrink: above the base moisture, the water to dry back to it,
 *        net × (moisture − base) ÷ (100 − base);
 *  - handling: a flat percentage of net on a load that needs drying (the drying and handling loss);
 *  - screenings / foreign matter: kilogram for kilogram above the allowance.
 * Settled mass = net − the three. Each is rounded to the kilogram, as weighbridge slips are.
 */
class GrainGrading
{
    public const SOURCE = 'Starting point modelled on the Agricultural Product Standards Act grading regulations; check against the current regulations and your buyer\'s tables.';

    /**
     * crop code => [rules, grades]. Grade limits: max/min per measured quantity (foreign_matter_pct,
     * defects_pct, other_colour_pct, protein_pct, hectolitre_kg). The last grade has no limits:
     * what fails every other grade is that class.
     */
    public const DEFAULTS = [
        'MAIZE-W' => [
            'rules' => ['base_moisture_pct' => 12.5, 'max_moisture_pct' => 18, 'handling_pct' => 0.5, 'screenings_allowance_pct' => 0.5],
            'grades' => [
                ['code' => 'WM1', 'name' => 'White maize grade 1', 'max' => ['foreign_matter_pct' => 0.3, 'defects_pct' => 7, 'other_colour_pct' => 3]],
                ['code' => 'WM2', 'name' => 'White maize grade 2', 'max' => ['foreign_matter_pct' => 0.5, 'defects_pct' => 13, 'other_colour_pct' => 5]],
                ['code' => 'WM3', 'name' => 'White maize grade 3', 'max' => ['foreign_matter_pct' => 0.75, 'defects_pct' => 30, 'other_colour_pct' => 5]],
                ['code' => 'COW', 'name' => 'Class other white maize', 'max' => []],
            ],
        ],
        'MAIZE-Y' => [
            'rules' => ['base_moisture_pct' => 12.5, 'max_moisture_pct' => 18, 'handling_pct' => 0.5, 'screenings_allowance_pct' => 0.5],
            'grades' => [
                ['code' => 'YM1', 'name' => 'Yellow maize grade 1', 'max' => ['foreign_matter_pct' => 0.3, 'defects_pct' => 7, 'other_colour_pct' => 5]],
                ['code' => 'YM2', 'name' => 'Yellow maize grade 2', 'max' => ['foreign_matter_pct' => 0.5, 'defects_pct' => 13, 'other_colour_pct' => 10]],
                ['code' => 'YM3', 'name' => 'Yellow maize grade 3', 'max' => ['foreign_matter_pct' => 0.75, 'defects_pct' => 30, 'other_colour_pct' => 10]],
                ['code' => 'COY', 'name' => 'Class other yellow maize', 'max' => []],
            ],
        ],
        'WHEAT' => [
            'rules' => ['base_moisture_pct' => 13, 'max_moisture_pct' => 15, 'handling_pct' => 0.5, 'screenings_allowance_pct' => 0.5],
            'grades' => [
                ['code' => 'B1', 'name' => 'Bread wheat grade 1', 'min' => ['protein_pct' => 12, 'hectolitre_kg' => 77], 'max' => ['foreign_matter_pct' => 1]],
                ['code' => 'B2', 'name' => 'Bread wheat grade 2', 'min' => ['protein_pct' => 11, 'hectolitre_kg' => 76], 'max' => ['foreign_matter_pct' => 1]],
                ['code' => 'B3', 'name' => 'Bread wheat grade 3', 'min' => ['protein_pct' => 10, 'hectolitre_kg' => 74], 'max' => ['foreign_matter_pct' => 1]],
                ['code' => 'B4', 'name' => 'Bread wheat grade 4', 'min' => ['protein_pct' => 9, 'hectolitre_kg' => 72], 'max' => ['foreign_matter_pct' => 1]],
                ['code' => 'UT', 'name' => 'Utility wheat', 'max' => []],
            ],
        ],
        'SOY' => [
            'rules' => ['base_moisture_pct' => 13, 'max_moisture_pct' => 15, 'handling_pct' => 0.5, 'screenings_allowance_pct' => 0.5],
            'grades' => [
                ['code' => 'SB1', 'name' => 'Soybeans grade 1', 'max' => ['foreign_matter_pct' => 4, 'defects_pct' => 10]],
                ['code' => 'COSB', 'name' => 'Class other soybeans', 'max' => []],
            ],
        ],
        'SUNFLOWER' => [
            'rules' => ['base_moisture_pct' => 10, 'max_moisture_pct' => 12, 'handling_pct' => 0.5, 'screenings_allowance_pct' => 0.5],
            'grades' => [
                ['code' => 'FH1', 'name' => 'Sunflower seed grade 1', 'max' => ['foreign_matter_pct' => 4, 'defects_pct' => 10]],
                ['code' => 'COS', 'name' => 'Class other sunflower seed', 'max' => []],
            ],
        ],
    ];

    /** The quantities a grade may be judged on, and how they read. */
    public const METRICS = ['foreign_matter_pct' => 'foreign matter %', 'defects_pct' => 'defective kernels %', 'other_colour_pct' => 'other colour %',
        'protein_pct' => 'protein %', 'hectolitre_kg' => 'hectolitre mass kg/hl'];

    /** One grain standard per grain crop the organisation grows, made the first time it is needed. Idempotent. */
    public function ensureDefaults(int $companyId): int
    {
        $n = 0;
        $types = CropType::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('code', array_keys(self::DEFAULTS))->get();
        foreach ($types as $type) {
            if (GradingStandard::withoutGlobalScopes()->where('company_id', $companyId)->where('crop_type_id', $type->id)->whereNotNull('grain_rules')->exists()) {
                continue;
            }
            $d = self::DEFAULTS[$type->code];
            GradingStandard::withoutGlobalScopes()->create(['company_id' => $companyId, 'crop_type_id' => $type->id, 'crop_code' => $type->code,
                'name' => "SA grain grading — {$type->name}", 'version' => 1, 'is_active' => true,
                'grades' => array_map(fn ($g) => $g + ['criteria' => $this->describe($g)], $d['grades']),
                'checks' => array_map(fn ($m) => ['check' => self::METRICS[$m], 'unit' => str_ends_with($m, '_kg') ? 'kg/hl' : '%'], ['foreign_matter_pct', 'defects_pct', 'protein_pct']),
                'grain_rules' => $d['rules'] + ['source' => self::SOURCE]]);
            $n++;
        }

        return $n;
    }

    /** The active grain standard for a crop, if the organisation has one. */
    public function standardFor(?int $cropTypeId): ?GradingStandard
    {
        if (! $cropTypeId) {
            return null;
        }
        $find = fn () => GradingStandard::query()->where('crop_type_id', $cropTypeId)->where('is_active', true)->whereNotNull('grain_rules')->orderByDesc('version')->first();
        $s = $find();
        if (! $s && ($type = CropType::query()->find($cropTypeId)) && isset(self::DEFAULTS[$type->code])) {
            $this->ensureDefaults((int) $type->company_id);
            $s = $find();
        }

        return $s;
    }

    /**
     * @return array{moisture_kg: float, handling_kg: float, screenings_kg: float, settled_kg: float, basis: list<string>}
     */
    public function deductions(float $netKg, ?float $moisture, ?float $foreignMatter, ?array $rules): array
    {
        $basis = [];
        $moistureKg = $handlingKg = $screeningsKg = 0.0;
        if ($rules && $netKg > 0) {
            $base = (float) $rules['base_moisture_pct'];
            if ($moisture !== null && $moisture > $base) {
                $moistureKg = round($netKg * ($moisture - $base) / (100 - $base));
                $basis[] = sprintf('Moisture %s%% over the %s%% base: %s × (%s − %s) ÷ (100 − %s) = %s kg', $this->n($moisture), $this->n($base),
                    $this->n($netKg), $this->n($moisture), $this->n($base), $this->n($base), $this->n($moistureKg));
                $handling = (float) ($rules['handling_pct'] ?? 0);
                if ($handling > 0) {
                    $handlingKg = round($netKg * $handling / 100);
                    $basis[] = sprintf('Handling (drying) %s%% of %s = %s kg', $this->n($handling), $this->n($netKg), $this->n($handlingKg));
                }
            }
            $allow = (float) ($rules['screenings_allowance_pct'] ?? 0);
            if ($foreignMatter !== null && $foreignMatter > $allow) {
                $screeningsKg = round($netKg * ($foreignMatter - $allow) / 100);
                $basis[] = sprintf('Foreign matter %s%% over the %s%% allowance: %s kg', $this->n($foreignMatter), $this->n($allow), $this->n($screeningsKg));
            }
        }
        if (! $basis) {
            $basis[] = $rules ? 'No deductions: dry enough and clean.' : 'No grain grading standard for this crop: settled at the net mass.';
        }

        return ['moisture_kg' => $moistureKg, 'handling_kg' => $handlingKg, 'screenings_kg' => $screeningsKg,
            'settled_kg' => round($netKg - $moistureKg - $handlingKg - $screeningsKg, 1), 'basis' => $basis];
    }

    /** The best grade whose limits every measured quantity meets; a quantity not measured is not held against it. */
    public function suggestGrade(?GradingStandard $s, array $measured): ?string
    {
        if (! $s) {
            return null;
        }
        foreach ($s->grades ?? [] as $g) {
            $ok = true;
            foreach ((array) ($g['max'] ?? []) as $m => $limit) {
                if (isset($measured[$m]) && $measured[$m] !== null && (float) $measured[$m] > (float) $limit) {
                    $ok = false;
                }
            }
            foreach ((array) ($g['min'] ?? []) as $m => $limit) {
                if (isset($measured[$m]) && $measured[$m] !== null && (float) $measured[$m] < (float) $limit) {
                    $ok = false;
                }
            }
            if ($ok) {
                return (string) $g['code'];
            }
        }

        return null;
    }

    private function describe(array $g): string
    {
        $parts = [];
        foreach ((array) ($g['max'] ?? []) as $m => $v) {
            $parts[] = (self::METRICS[$m] ?? $m)." ≤ {$v}";
        }
        foreach ((array) ($g['min'] ?? []) as $m => $v) {
            $parts[] = (self::METRICS[$m] ?? $m)." ≥ {$v}";
        }

        return $parts ? implode(', ', $parts) : 'Below every other grade';
    }

    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ' '), '0'), '.');
    }
}
