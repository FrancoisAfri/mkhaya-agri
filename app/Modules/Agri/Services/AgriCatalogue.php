<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\LivestockBreed;
use App\Modules\Agri\Models\LivestockSpecies;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The starting catalogue every organisation gets: the crops, livestock species and breeds farmed in
 * Southern Africa, and the current summer and winter seasons. Installed once, the first time the
 * organisation opens a crop or livestock screen; after that it is theirs to edit, and nothing here
 * overwrites a change they made.
 */
class AgriCatalogue
{
    /** code => [name, category, yield unit, [varieties => days to maturity]] */
    private const CROPS = [
        'MAIZE-W' => ['White maize', 'grain', 't', ['DKC 78-79' => 140, 'PAN 5R-591R' => 135]],
        'MAIZE-Y' => ['Yellow maize', 'grain', 't', ['DKC 73-74' => 130, 'P 2432R' => 128]],
        'WHEAT' => ['Wheat', 'grain', 't', ['SST 8154' => 150, 'PAN 3471' => 145]],
        'SORGHUM' => ['Sorghum', 'grain', 't', []],
        'BARLEY' => ['Barley', 'grain', 't', []],
        'SOY' => ['Soybean', 'oilseed', 't', ['PAN 1521R' => 120, 'DM 5953RSF' => 125]],
        'SUNFLOWER' => ['Sunflower', 'oilseed', 't', ['PAN 7160 CLP' => 120]],
        'CANOLA' => ['Canola', 'oilseed', 't', []],
        'GROUNDNUT' => ['Groundnuts', 'legume', 't', []],
        'DRYBEAN' => ['Dry beans', 'legume', 't', []],
        'POTATO' => ['Potatoes', 'vegetable', 't', ['Mondial' => 110, 'Sifra' => 100]],
        'TOMATO' => ['Tomatoes', 'vegetable', 'crates', []],
        'CABBAGE' => ['Cabbage', 'vegetable', 'heads', []],
        'ONION' => ['Onions', 'vegetable', 't', []],
        'CITRUS' => ['Citrus', 'fruit', 't', []],
        'APPLE' => ['Apples', 'fruit', 'bins', []],
        'GRAPE' => ['Table grapes', 'fruit', 't', []],
        'AVOCADO' => ['Avocados', 'fruit', 't', []],
        'MACADAMIA' => ['Macadamias', 'nut', 't', []],
        'SUGARCANE' => ['Sugarcane', 'sugar', 't', []],
        'COTTON' => ['Cotton', 'fibre', 't', []],
        'LUCERNE' => ['Lucerne', 'fodder', 'bales', []],
        'OATS-FOD' => ['Oats (fodder)', 'fodder', 'bales', []],
        'TEFF' => ['Teff', 'fodder', 'bales', []],
    ];

    /** code => [name, tracking, gestation days, [breeds]] */
    private const SPECIES = [
        'CATTLE' => ['Cattle', 'individual', 283, ['Bonsmara', 'Nguni', 'Brahman', 'Angus', 'Simmentaler', 'Holstein', 'Jersey', 'Afrikaner', 'Drakensberger']],
        'SHEEP' => ['Sheep', 'individual', 150, ['Dorper', 'Merino', 'Dohne Merino', 'Meatmaster', 'Damara']],
        'GOAT' => ['Goats', 'individual', 150, ['Boer goat', 'Kalahari Red', 'Savanna', 'Angora', 'Saanen']],
        'PIG' => ['Pigs', 'individual', 114, ['Large White', 'Landrace', 'Duroc', 'Kolbroek']],
        'CHICKEN-L' => ['Chickens (layers)', 'group', null, ['Lohmann Brown', 'Hy-Line Brown', 'Potchefstroom Koekoek']],
        'CHICKEN-B' => ['Chickens (broilers)', 'group', null, ['Ross 308', 'Cobb 500']],
        'HORSE' => ['Horses', 'individual', 340, ['Boerperd', 'Nooitgedacht', 'Thoroughbred']],
        'OSTRICH' => ['Ostriches', 'group', null, []],
        'GAME' => ['Game', 'group', null, ['Springbok', 'Kudu', 'Impala', 'Blesbok']],
        'FISH' => ['Fish (aquaculture)', 'group', null, ['Tilapia', 'Rainbow trout']],
        'BEES' => ['Bees (hives)', 'group', null, []],
    ];

    public function ensureDefaults(?int $companyId = null): void
    {
        $companyId ??= app(CurrentCompany::class)->id() ?? Auth::user()?->company_id;
        if (! $companyId) {
            return;
        }

        if (! CropType::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            $this->installCrops($companyId);
        }
        if (! LivestockSpecies::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            $this->installSpecies($companyId);
        }
        if (! CropSeason::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            $this->installSeasons($companyId);
        }
    }

    private function installCrops(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            foreach (self::CROPS as $code => [$name, $category, $unit, $varieties]) {
                $type = CropType::create(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'category' => $category, 'yield_unit' => $unit]);
                foreach ($varieties as $v => $days) {
                    CropVariety::create(['company_id' => $companyId, 'crop_type_id' => $type->id, 'name' => $v, 'days_to_maturity' => $days]);
                }
            }
        });
    }

    private function installSpecies(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            foreach (self::SPECIES as $code => [$name, $tracking, $gestation, $breeds]) {
                $s = LivestockSpecies::create(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'tracking' => $tracking, 'gestation_days' => $gestation]);
                foreach ($breeds as $b) {
                    LivestockBreed::create(['company_id' => $companyId, 'species_id' => $s->id, 'name' => $b]);
                }
            }
        });
    }

    /** Southern-hemisphere seasons: summer crops plant Sep–Jan, winter crops Apr–Jul. */
    private function installSeasons(int $companyId): void
    {
        $y = (int) today()->year;
        $summerStart = today()->month >= 8 ? $y : $y - 1;
        $seasons = [
            ["S{$summerStart}/".substr((string) ($summerStart + 1), 2), "Summer {$summerStart}/".substr((string) ($summerStart + 1), 2), "{$summerStart}-08-01", ($summerStart + 1).'-07-31'],
            ["W{$y}", "Winter {$y}", "{$y}-03-01", ($y + 1).'-01-31'],
            ['S'.($summerStart + 1).'/'.substr((string) ($summerStart + 2), 2), 'Summer '.($summerStart + 1).'/'.substr((string) ($summerStart + 2), 2), ($summerStart + 1).'-08-01', ($summerStart + 2).'-07-31'],
        ];
        foreach ($seasons as [$code, $name, $from, $to]) {
            CropSeason::create(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'starts_on' => $from, 'ends_on' => $to, 'status' => CropSeason::OPEN]);
        }
    }
}
