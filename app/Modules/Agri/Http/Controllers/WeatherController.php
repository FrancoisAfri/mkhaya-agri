<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\EnvironmentRecord;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\LivestockSpecies;
use App\Modules\Agri\Models\WeatherRule;
use App\Modules\Agri\Services\EnvironmentReport;
use App\Modules\Agri\Services\WeatherIntelligence;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 §11: the farm's weather (with source, fetch time and period), the approved weather rules
 * and the alerts they raise, environmental records and the sustainability report.
 */
class WeatherController extends AgriController
{
    use FileStorage;

    public const TABS = ['weather', 'rules', 'environment', 'sustainability'];

    public function __construct(private readonly WeatherIntelligence $weather, private readonly EnvironmentReport $env) {}

    public function index(Request $request, string $tab = 'weather'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_FARMS);
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $from = $request->date('from')?->toDateString() ?? today()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();
        $provider = $this->weather->provider();

        return Inertia::render('Modules/Agri/Weather/Index', [
            'tab' => $tab, 'from' => $from, 'to' => $to,
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name, 'latitude' => $farm->latitude, 'longitude' => $farm->longitude] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'provider' => $provider ? ['key' => $provider['key'], 'name' => $provider['name']] : null,
            'data' => $farm ? match ($tab) {
                'weather' => $this->weather->summary($farm) + ['alerts' => FarmAlert::query()->where('farm_id', $farm->id)->whereIn('kind', ['weather', 'weather_warning'])
                    ->whereIn('status', [FarmAlert::OPEN, FarmAlert::ACKNOWLEDGED])->orderBy('due_on')->get(['uuid', 'severity', 'title', 'message', 'link', 'due_on'])],
                'rules' => ['rules' => WeatherRule::query()->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $farm->id))->orderByRaw("CASE status WHEN 'draft' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderBy('name')->get()],
                'environment' => ['records' => EnvironmentRecord::query()->where('farm_id', $farm->id)->whereBetween('recorded_on', [$from, $to])->orderByDesc('recorded_on')->limit(300)->get()],
                'sustainability' => $this->env->summary($farm, $from, $to),
            } : [],
            'options' => ['activities' => WeatherRule::ACTIVITIES, 'metrics' => [...WeatherRule::METRICS, 'rain_total'], 'kinds' => EnvironmentRecord::KINDS,
                'crop_types' => CropType::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'title' => $c->name]),
                'species' => LivestockSpecies::query()->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'title' => $s->name]),
                'chemicals' => FinStockItem::query()->where('is_active', true)->whereIn('id', \App\Modules\Agri\Models\ChemicalProduct::query()->pluck('stock_item_id'))->get(['id', 'name'])
                    ->map(fn ($i) => ['value' => $i->id, 'title' => $i->name])],
            'can' => ['rules' => $request->user()->can(R::MANAGE_WEATHER), 'record' => $request->user()->can(R::RECORD_ACTIVITIES), 'refresh' => $request->user()->can(R::MANAGE_FARMS) || $request->user()->can(R::MANAGE_WEATHER)],
        ]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FARMS, R::MANAGE_WEATHER);
        $farm = $this->farm($request->string('farm_id')->value());
        abort_if($farm->latitude === null || $farm->longitude === null, 422, 'Set the farm\'s location (latitude and longitude) first.');
        abort_unless($this->weather->provider(), 422, 'No approved weather provider is configured.');
        try {
            $n = $this->weather->refresh($farm);
            if ($request->boolean('history')) {
                $this->weather->history($farm, today()->subDays(30)->toDateString(), today()->subDay()->toDateString());
            }
        } catch (\RuntimeException $e) {
            return back()->with('error', 'The weather provider did not answer: '.$e->getMessage());
        }
        app(\App\Modules\Agri\Services\FarmAlerts::class)->scan((int) $farm->company_id);

        return back()->with('success', "{$n} forecast hour(s) fetched for {$farm->name}.");
    }

    public function saveRule(Request $request, ?WeatherRule $rule = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WEATHER);
        $data = $request->validate(['farm_id' => ['nullable', 'string'], 'name' => ['required', 'string', 'max:160'], 'activity' => ['required', Rule::in(WeatherRule::ACTIVITIES)],
            'crop_type_id' => ['nullable', 'integer'], 'stock_item_id' => ['nullable', 'integer'], 'species_id' => ['nullable', 'integer'],
            'conditions' => ['required', 'array', 'min:1'], 'conditions.*.metric' => ['required', 'string'], 'conditions.*.op' => ['required', Rule::in(['>', '>=', '<', '<='])],
            'conditions.*.value' => ['required', 'numeric'], 'lookahead_hours' => ['required', 'integer', 'between:1,240'], 'advice' => ['required', 'string', 'max:500'],
            'basis' => ['required', 'string', 'max:300'], 'severity' => ['required', Rule::in(['info', 'warning', 'critical'])]]);
        $data['farm_id'] = ! empty($data['farm_id']) ? $this->workingFarm($request, $data['farm_id'])->id : null;
        $this->weather->saveRule($data, $request->user(), $rule);

        return back()->with('success', 'Rule saved as a draft. Someone else approves the threshold before it raises alerts.');
    }

    public function approveRule(Request $request, WeatherRule $rule): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WEATHER);
        $this->weather->approveRule($rule, $request->user());
        app(\App\Modules\Agri\Services\FarmAlerts::class)->scan((int) $rule->company_id);

        return back()->with('success', 'Rule approved; it now raises alerts.');
    }

    public function retireRule(Request $request, WeatherRule $rule): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WEATHER);
        $rule->update(['status' => 'retired']);

        return back()->with('success', 'Rule retired.');
    }

    public function storeRecord(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'field_id' => ['nullable', 'string'], 'kind' => ['required', Rule::in(EnvironmentRecord::KINDS)],
            'recorded_on' => ['required', 'date', 'before_or_equal:today'], 'quantity' => ['nullable', 'numeric', 'gte:0'], 'unit' => ['nullable', 'string', 'max:16'],
            'method' => ['nullable', 'string', 'max:120'], 'description' => ['required', 'string', 'max:1000'], 'details' => ['nullable', 'array'],
            'verification' => ['nullable', Rule::in(['unverified', 'verified'])], 'methodology' => ['nullable', 'string', 'max:200'], 'verifier' => ['nullable', 'string', 'max:160'],
            'evidence' => ['nullable', 'file', 'max:15360']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $data['field_id'] = $this->fieldOn($farm, $data['field_id'] ?? null)?->id;
        $evidence = $request->hasFile('evidence') ? $this->saveFileToDatabase($request->file('evidence')) : null;
        $this->env->record($farm, collect($data)->except(['farm_id', 'evidence'])->all(), $request->user(), $evidence);

        return back()->with('success', 'Recorded.');
    }

    public function evidence(Request $request, EnvironmentRecord $environmentRecord): \Illuminate\Http\Response
    {
        $this->allow($request, R::VIEW_FARMS);
        abort_unless($environmentRecord->evidence_data, 404);

        return $this->downloadResponse($environmentRecord->evidence_data, (string) $environmentRecord->evidence_mime, (string) $environmentRecord->evidence_name);
    }
}
