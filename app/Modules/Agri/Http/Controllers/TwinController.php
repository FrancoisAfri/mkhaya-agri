<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\ImageryFlag;
use App\Modules\Agri\Models\Simulation;
use App\Modules\Agri\Services\CropImagery;
use App\Modules\Agri\Services\DecisionSimulator;
use App\Modules\Agri\Services\FarmTwin;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 §16: the farm map with its live state (A), crop imagery and its flags (B), and the
 * production-plan simulator (E).
 */
class TwinController extends AgriController
{
    use FileStorage;

    public const TABS = ['map', 'imagery', 'simulator'];

    public function __construct(private readonly FarmTwin $twin, private readonly CropImagery $imagery, private readonly DecisionSimulator $simulator) {}

    public function index(Request $request, string $tab = 'map'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_FARMS);
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();

        return Inertia::render('Modules/Agri/Twin', [
            'tab' => $tab,
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'data' => $farm ? match ($tab) {
                'map' => $this->twin->build($farm),
                'imagery' => ['captures' => FieldImagery::query()->where('farm_id', $farm->id)->orderByDesc('captured_on')->limit(200)->get()
                    ->map(fn ($i) => $i->toArray() + ['field' => \App\Modules\Agri\Models\FarmField::query()->whereKey($i->field_id)->value('code')]),
                    'flags' => ImageryFlag::query()->where('farm_id', $farm->id)->orderByRaw("CASE validation WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(100)->get(),
                    'track' => $this->imagery->track($farm->id)],
                'simulator' => ['simulations' => Simulation::query()->where('farm_id', $farm->id)->orderByDesc('id')->limit(30)->get()],
            } : [],
            'options' => ['fields' => $farm ? \App\Modules\Agri\Models\FarmField::query()->where('farm_id', $farm->id)->orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn ($f) => ['value' => $f->uuid, 'title' => "{$f->code} {$f->name}"]) : [], 'indices' => FieldImagery::INDICES,
                'crop_types' => CropType::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'title' => $c->name]),
                'varieties' => CropVariety::query()->orderBy('name')->get(['id', 'crop_type_id', 'name', 'days_to_maturity']),
                'seasons' => \App\Modules\Agri\Models\CropSeason::query()->where('status', 'open')->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'title' => $s->name]),
                'changes' => DecisionSimulator::CHANGES],
            'can' => ['record' => $request->user()->can(R::RECORD_ACTIVITIES), 'plan' => $request->user()->can(R::PLAN_CROPS)],
        ]);
    }

    public function importImagery(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'field_id' => ['required', 'string'], 'captured_on' => ['required', 'date', 'before_or_equal:today'],
            'source' => ['required', 'string', 'max:60'], 'index' => ['required', Rule::in(FieldImagery::INDICES)], 'mean' => ['required', 'numeric', 'between:-1,1'],
            'min' => ['nullable', 'numeric', 'between:-1,1'], 'max' => ['nullable', 'numeric', 'between:-1,1'], 'cloud_pct' => ['nullable', 'numeric', 'between:0,100'],
            'cells' => ['nullable', 'array'], 'cells.*.id' => ['required', 'string', 'max:40'], 'cells.*.value' => ['required', 'numeric', 'between:-1,1'],
            'cells.*.lat' => ['nullable', 'numeric'], 'cells.*.lng' => ['nullable', 'numeric'], 'image' => ['nullable', 'image', 'max:10240']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $field = $this->fieldOn($farm, $data['field_id']);
        if ($request->hasFile('image')) {
            $f = $this->saveFileToDatabase($request->file('image'));
            $data += ['image_mime' => $f['mime'], 'image_data' => $f['data']];
        }
        $r = $this->imagery->import($field, collect($data)->except(['farm_id', 'field_id', 'image'])->all(), $request->user());

        return back()->with($r['flag'] ? 'error' : 'success', $r['flag'] ? "Flagged: {$r['flag']->reason}. A scouting check is open." : 'Capture recorded.'.($r['change_pct'] !== null ? " Change {$r['change_pct']}%." : ''));
    }

    public function validateFlag(Request $request, ImageryFlag $flag): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $flag->farm_id);
        $data = $request->validate(['outcome' => ['required', Rule::in(['confirmed', 'false_alarm'])], 'note' => ['nullable', 'string', 'max:500']]);
        $this->imagery->validate($flag, $data['outcome'], $data['note'] ?? null, $request->user());

        return back()->with('success', 'Recorded. The imagery\'s track record is updated.');
    }

    public function simulate(Request $request): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'name' => ['required', 'string', 'max:160'], 'field_id' => ['nullable', 'string'], 'crop_type_id' => ['required', 'integer'],
            'base' => ['required', 'array'], 'base.hectares' => ['required', 'numeric', 'gt:0'], 'base.planting_date' => ['required', 'date'], 'base.yield_per_ha' => ['required', 'numeric', 'gt:0'],
            'base.price' => ['required', 'numeric', 'gt:0'], 'base.*' => ['nullable'], 'scenarios' => ['nullable', 'array'], 'scenarios.*.name' => ['required', 'string', 'max:120'],
            'scenarios.*.changes' => ['nullable', 'array']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $data['field_id'] = $this->fieldOn($farm, $data['field_id'] ?? null)?->id;
        $sim = $this->simulator->create($farm, $data, $request->user());

        return redirect()->route('agri.twin', ['tab' => 'simulator', 'farm' => $farm->uuid, 'sim' => $sim->uuid])->with('success', 'Simulated '.count($sim->results['scenarios']).' option(s).');
    }

    public function adopt(Request $request, Simulation $simulation): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $this->assertWritable($request, $simulation->farm_id);
        $data = $request->validate(['scenario' => ['required', 'string'], 'season_id' => ['required', 'integer']]);
        $plan = $this->simulator->adopt($simulation, $data['scenario'], (int) $data['season_id'], $request->user());

        return redirect()->route('agri.crops.plans.show', $plan->uuid)->with('success', "Crop plan {$plan->code} drafted from \"{$data['scenario']}\". It goes for approval as usual.");
    }
}
