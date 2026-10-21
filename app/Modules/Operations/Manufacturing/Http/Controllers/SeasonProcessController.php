<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\SoilTest;
use App\Modules\Agri\Processes\CropSeasonProcess;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNodeRun;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Process 1 on the farm: start a season, enter the lab's soil result, report hail or drought, and
 * see where every field's season is. The steps themselves are done in the Workflow inbox.
 */
class SeasonProcessController extends Controller
{
    public function __construct(private readonly FarmAccess $access) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Cycle::class);
        $farms = $this->access->farmIdsFor($request->user());
        $def = WorkflowDefinition::query()->where('key', 'agri_field_season')->first();
        $runs = $def ? WorkflowInstance::query()->where('definition_id', $def->id)->latest('id')->limit(200)->get() : collect();
        $runs = $runs->filter(fn ($i) => $farms === null || in_array((int) ($i->data['farm_id'] ?? 0), $farms, true));
        $waiting = WorkflowNodeRun::query()->whereIn('instance_id', $runs->pluck('id'))->where('status', 'waiting')->get()->groupBy('instance_id');
        $tasks = WorkflowTask::query()->whereIn('instance_id', $runs->pluck('id'))->where('status', 'open')->get()->groupBy('instance_id');
        $published = WorkflowDefinition::query()->whereIn('key', CropSeasonProcess::KEYS)->pluck('status', 'key');

        return Inertia::render('Modules/Manufacturing/Agriculture/Seasons', [
            'fields' => $runs->map(fn (WorkflowInstance $i) => ['id' => $i->uuid, 'reference' => $i->reference, 'status' => $i->status,
                'field' => $i->data['field']['name'] ?? $i->data['field_code'] ?? '—', 'farm' => $i->data['farm_name'] ?? null, 'season' => $i->data['season_name'] ?? null,
                'crop' => $i->data['crop'] ?? null, 'variety' => $i->data['variety_name'] ?? null, 'ph' => $i->data['soil']['ph'] ?? null,
                'planted_on' => $i->data['planted_on'] ?? null, 'harvest_from' => $i->data['harvest_from'] ?? null, 'withholding_until' => $i->data['withholding_until'] ?? null,
                'yield' => $i->data['yield_t_per_ha'] ?? null, 'damage' => $i->data['damage']['kind'] ?? null,
                'soil_test' => empty($i->data['soil']) ? SoilTest::query()->whereKey($i->data['soil_test_id'] ?? 0)->value('uuid') : null,
                'waiting' => ($waiting[$i->id] ?? collect())->map(fn ($r) => $i->version?->node($r->node_key)['name'] ?? $r->node_key)->values(),
                'tasks' => ($tasks[$i->id] ?? collect())->pluck('name')->values()])->values(),
            'farms' => Farm::query()->where('status', Farm::ACTIVE)->when($farms !== null, fn ($q) => $q->whereIn('id', $farms))->orderBy('name')->get(['uuid', 'name'])
                ->map(fn ($f) => ['value' => $f->uuid, 'title' => $f->name])->values(),
            'seasons' => CropSeason::query()->where('status', CropSeason::OPEN)->orderBy('starts_on')->get(['uuid', 'name', 'starts_on'])
                ->map(fn ($s) => ['value' => $s->uuid, 'title' => $s->name])->values(),
            'cropFields' => FarmField::query()->where('status', 'active')->where('land_use_type', 'field')->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))
                ->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($f) => ['value' => $f->uuid, 'title' => "{$f->code} {$f->name}"])->values(),
            'installed' => $published->count() === 4 && $published->every(fn ($s) => $s === 'published'),
            'processes' => collect(CropSeasonProcess::KEYS)->map(fn ($k) => ['key' => $k, 'status' => $published[$k] ?? 'not installed'])->values(),
            'can' => ['plan' => $request->user()->can('manage', Cycle::class), 'record' => $request->user()->can('record', Cycle::class)],
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        abort_unless($request->user()->can(\App\Modules\Workflow\Support\WorkflowPermissions::DESIGN), 403, 'Installing workflows needs the workflow designer permission.');
        try {
            $status = CropSeasonProcess::install($request->user());
        } catch (WorkflowException $e) {
            throw ValidationException::withMessages(['install' => $e->getMessage()]);
        }
        $waiting = collect($status)->filter(fn ($s) => $s !== 'published');
        if ($waiting->isNotEmpty() || count($status) < 4) {
            return back()->with('success', 'Installed. '.WorkflowDefinition::query()->where('key', $waiting->keys()->first())->value('name')
                .' is waiting for a second person to review it under Workflow → Definitions; press Install again once it is published to carry on.');
        }

        return back()->with('success', 'The crop season processes are installed and published. You are their process owner: approvals come to you.');
    }

    public function start(Request $request): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        $d = $request->validate(['farm_id' => ['required', 'uuid'], 'season_id' => ['required', 'uuid'], 'planting_window_start' => ['required', 'date'],
            'planting_window_end' => ['required', 'date', 'after:planting_window_start'], 'replant_window_end' => ['nullable', 'date', 'after:planting_window_start'],
            'rain_threshold_mm' => ['nullable', 'numeric', 'min:1', 'max:200'], 'laboratory' => ['nullable', 'string', 'max:120']]);
        $farm = Farm::query()->where('uuid', $d['farm_id'])->firstOrFail();
        abort_unless($this->access->canReach($request->user(), $farm->id), 404);
        $season = CropSeason::query()->where('uuid', $d['season_id'])->firstOrFail();
        try {
            $i = CropSeasonProcess::startSeason($farm, $season, $request->user(), array_filter($d, fn ($v) => $v !== null));
        } catch (WorkflowException $e) {
            throw ValidationException::withMessages(['farm_id' => $e->getMessage()]);
        }

        return back()->with('success', "{$i->reference} started: a field season for each crop field on {$farm->name}.");
    }

    public function soilResult(Request $request, string $test): RedirectResponse
    {
        $this->authorize('record', Cycle::class);
        $t = SoilTest::query()->where('uuid', $test)->firstOrFail();
        abort_unless($this->access->canReach($request->user(), $t->farm_id), 404);
        $d = $request->validate(['ph' => ['required', 'numeric', 'between:2,11'], 'p_mg_kg' => ['nullable', 'numeric', 'min:0'], 'k_mg_kg' => ['nullable', 'numeric', 'min:0'],
            'recommendation' => ['nullable', 'string', 'max:2000']]);
        $t->update($d); // the field season waiting for this sample continues

        return back()->with('success', "Soil result recorded (pH {$d['ph']}).");
    }

    public function damage(Request $request): RedirectResponse
    {
        $this->authorize('record', Cycle::class);
        $d = $request->validate(['field_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['hail', 'drought', 'flood', 'frost', 'fire'])], 'notes' => ['nullable', 'string', 'max:1000']]);
        $field = FarmField::query()->where('uuid', $d['field_id'])->firstOrFail();
        abort_unless($this->access->canReach($request->user(), $field->farm_id), 404);
        $r = CropSeasonProcess::reportDamage($field, $d['kind'], $d['notes'] ?? null, $request->user());

        return back()->with('success', $r['interrupted'] ? ucfirst($d['kind'])." reported on {$field->code}: the field season stopped for a damage assessment."
            : ucfirst($d['kind'])." recorded on {$field->code}; no field season is running there.");
    }
}
