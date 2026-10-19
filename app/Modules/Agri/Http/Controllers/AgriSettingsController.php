<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\LivestockBreed;
use App\Modules\Agri\Models\LivestockSpecies;
use App\Modules\Agri\Services\AgriCatalogue;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation's farming catalogues: crop types and varieties, seasons, livestock species and
 * breeds. Shared by every farm in the organisation.
 */
class AgriSettingsController extends AgriController
{
    private const KINDS = [
        'crop_types' => CropType::class, 'varieties' => CropVariety::class, 'seasons' => CropSeason::class,
        'species' => LivestockSpecies::class, 'breeds' => LivestockBreed::class,
    ];

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_FARMS);
        app(AgriCatalogue::class)->ensureDefaults();

        return Inertia::render('Modules/Agri/Settings', [
            'crop_types' => CropType::query()->withCount('varieties')->orderBy('name')->get(),
            'varieties' => CropVariety::query()->with('cropType:id,name')->orderBy('name')->get(),
            'seasons' => CropSeason::query()->orderByDesc('starts_on')->get()->map(fn ($s) => $s->toArray() + [
                'starts_on' => $s->starts_on->toDateString(), 'ends_on' => $s->ends_on->toDateString()]),
            'species' => LivestockSpecies::query()->withCount('breeds')->orderBy('name')->get(),
            'breeds' => LivestockBreed::query()->with('species:id,name')->orderBy('name')->get(),
            'options' => ['crop_categories' => CropType::CATEGORIES, 'yield_units' => CropType::YIELD_UNITS, 'tracking' => LivestockSpecies::TRACKING],
            'can' => ['manage' => $request->user()->can(R::MANAGE_SETTINGS)],
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SETTINGS);
        abort_unless(isset(self::KINDS[$kind]), 404);
        $class = self::KINDS[$kind];
        $data = $request->validate($this->rules($kind));
        $this->assertUnique($kind, $data);
        $class::create($data);

        return back()->with('success', 'Added.');
    }

    public function update(Request $request, string $kind, string $uuid): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SETTINGS);
        abort_unless(isset(self::KINDS[$kind]), 404);
        $record = self::KINDS[$kind]::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate($this->rules($kind));
        $this->assertUnique($kind, $data, $record->id);
        $record->update($data);

        return back()->with('success', 'Saved.');
    }

    private function rules(string $kind): array
    {
        return match ($kind) {
            'crop_types' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:120'],
                'category' => ['required', Rule::in(CropType::CATEGORIES)], 'yield_unit' => ['required', Rule::in(CropType::YIELD_UNITS)],
                'is_active' => ['boolean']],
            'varieties' => ['crop_type_id' => ['required', 'integer', Rule::exists('crop_types', 'id')], 'name' => ['required', 'string', 'max:120'],
                'days_to_maturity' => ['nullable', 'integer', 'min:1', 'max:3650'], 'is_active' => ['boolean']],
            'seasons' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:120'],
                'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'], 'status' => ['required', Rule::in(['open', 'closed'])]],
            'species' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:80'],
                'tracking' => ['required', Rule::in(LivestockSpecies::TRACKING)], 'gestation_days' => ['nullable', 'integer', 'min:1', 'max:800'], 'is_active' => ['boolean']],
            'breeds' => ['species_id' => ['required', 'integer', Rule::exists('livestock_species', 'id')], 'name' => ['required', 'string', 'max:80'], 'is_active' => ['boolean']],
        };
    }

    private function assertUnique(string $kind, array $data, ?int $except = null): void
    {
        $class = self::KINDS[$kind];
        $q = $class::query()->when($except, fn ($w) => $w->whereKeyNot($except));
        $clash = match ($kind) {
            'crop_types', 'seasons', 'species' => (clone $q)->whereRaw('lower(code) = ?', [mb_strtolower($data['code'])])->exists() ? 'code' : null,
            'varieties' => (clone $q)->where('crop_type_id', $data['crop_type_id'])->where('name', $data['name'])->exists() ? 'name' : null,
            'breeds' => (clone $q)->where('species_id', $data['species_id'])->where('name', $data['name'])->exists() ? 'name' : null,
        };
        if ($clash) {
            throw ValidationException::withMessages([$clash => 'That is already in the catalogue.']);
        }
    }
}
