<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalEvent;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\AnimalWeight;
use App\Modules\Agri\Models\LivestockBreed;
use App\Modules\Agri\Models\LivestockCountChange;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\LivestockSpecies;
use App\Modules\Agri\Services\AgriCatalogue;
use App\Modules\Agri\Services\Livestock;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module C: herds and flocks, individual animals, health, weights, breeding events and exits.
 */
class LivestockController extends AgriController
{
    public function __construct(private readonly Livestock $livestock) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_LIVESTOCK);
        app(AgriCatalogue::class)->ensureDefaults();
        $farm = $this->farmFilter($request);
        $status = $request->query('status', 'active');
        $search = trim((string) $request->query('search', ''));
        $scope = fn ($q) => $q->when($farm, fn ($w) => $w->where('farm_id', $farm->id));

        $groups = LivestockGroup::query()->tap($scope)->with(['farm', 'species', 'field'])
            ->withCount(['animals as active_animals' => fn ($q) => $q->where('status', Animal::ACTIVE)])
            ->orderBy('code')->get()->map(fn (LivestockGroup $g) => $this->groupRow($g));

        $animals = Animal::query()->tap($scope)
            ->when($status !== 'all', fn ($q) => $status === 'active' ? $q->where('status', Animal::ACTIVE) : $q->where('status', '!=', Animal::ACTIVE))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('tag_number', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")))
            ->with(['farm', 'species', 'breed', 'group', 'field'])->orderBy('tag_number')->limit(500)->get()
            ->map(fn (Animal $a) => $this->animalRow($a));

        $health = AnimalHealthRecord::query()->tap($scope)->with(['farm', 'animal', 'group'])->orderByDesc('treated_on')->limit(100)->get()
            ->map(fn ($h) => $this->healthRow($h));

        return Inertia::render('Modules/Agri/Livestock/Index', [
            'groups' => $groups,
            'animals' => $animals,
            'health' => $health,
            'filters' => ['farm' => $farm?->uuid, 'status' => $status, 'search' => $search, 'tab' => $request->query('tab', 'groups')],
            'options' => $this->options(),
            'can' => $this->can($request),
        ]);
    }

    public function showGroup(Request $request, LivestockGroup $group): Response
    {
        $this->allow($request, R::VIEW_LIVESTOCK);
        $group->load(['farm', 'species', 'field']);

        return Inertia::render('Modules/Agri/Livestock/Group', [
            'group' => $this->groupRow($group) + ['notes' => $group->notes, 'budget_amount' => self::num($group->budget_amount),
                'withdrawal_until' => $this->livestock->withdrawalUntil(null, $group)?->toDateString()],
            'animals' => $group->animals()->with(['species', 'breed', 'farm', 'group', 'field'])->orderBy('tag_number')->get()->map(fn ($a) => $this->animalRow($a)),
            'changes' => LivestockCountChange::query()->where('group_id', $group->id)->orderByDesc('changed_on')->orderByDesc('id')->get()
                ->map(fn ($c) => ['id' => $c->uuid, 'changed_on' => $c->changed_on->toDateString(), 'delta' => $c->delta, 'reason' => $c->reason,
                    'count_after' => $c->count_after, 'value' => self::num($c->value), 'notes' => $c->notes]),
            'health' => AnimalHealthRecord::query()->where('group_id', $group->id)->with(['farm', 'animal', 'group'])->orderByDesc('treated_on')->get()->map(fn ($h) => $this->healthRow($h)),
            'weights' => AnimalWeight::query()->where('group_id', $group->id)->whereNull('animal_id')->orderByDesc('weighed_on')->get()
                ->map(fn ($w) => ['weighed_on' => $w->weighed_on->toDateString(), 'weight_kg' => (float) $w->weight_kg, 'head_count' => $w->head_count]),
            'options' => $this->options(),
            'can' => $this->can($request),
        ]);
    }

    public function showAnimal(Request $request, Animal $animal): Response
    {
        $this->allow($request, R::VIEW_LIVESTOCK);
        $animal->load(['farm', 'species', 'breed', 'group', 'field', 'dam', 'sire']);

        return Inertia::render('Modules/Agri/Livestock/Animal', [
            'animal' => $this->animalRow($animal) + [
                'electronic_id' => $animal->electronic_id, 'acquisition' => $animal->acquisition,
                'acquired_on' => $animal->acquired_on?->toDateString(), 'acquisition_cost' => self::num($animal->acquisition_cost),
                'dam' => $animal->dam?->tag_number, 'sire' => $animal->sire?->tag_number, 'exit_reason' => $animal->exit_reason,
                'exit_value' => self::num($animal->exit_value), 'notes' => $animal->notes,
                'withdrawal_until' => $this->livestock->withdrawalUntil($animal, $animal->group)?->toDateString(),
            ],
            'health' => $animal->healthRecords()->with(['farm', 'animal', 'group'])->orderByDesc('treated_on')->get()->map(fn ($h) => $this->healthRow($h)),
            'events' => $animal->events()->orderByDesc('occurred_on')->get()->map(fn ($e) => ['event_type' => $e->event_type,
                'occurred_on' => $e->occurred_on->toDateString(), 'outcome' => $e->outcome]),
            'weights' => $animal->weights()->orderBy('weighed_on')->get()->map(fn ($w) => ['weighed_on' => $w->weighed_on->toDateString(),
                'weight_kg' => (float) $w->weight_kg, 'condition_score' => self::num($w->condition_score)]),
            'offspring' => Animal::query()->where(fn ($q) => $q->where('dam_id', $animal->id)->orWhere('sire_id', $animal->id))->get(['uuid', 'tag_number', 'sex', 'birth_date'])
                ->map(fn ($o) => ['id' => $o->uuid, 'tag_number' => $o->tag_number, 'sex' => $o->sex, 'birth_date' => $o->birth_date?->toDateString()]),
            'options' => $this->options(),
            'can' => $this->can($request),
        ]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK);
        $data = $request->validate($this->groupRules() + ['farm_id' => ['required', 'string'], 'head_count' => ['nullable', 'integer', 'min:0']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        $group = $this->livestock->createGroup($farm, ['field_id' => $field?->id] + collect($data)->except(['farm_id', 'field_id'])->all(), $request->user());

        return redirect()->route('agri.livestock.groups.show', $group->uuid)->with('success', "Group {$group->code} created.");
    }

    public function updateGroup(Request $request, LivestockGroup $group): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK);
        $this->assertWritable($request, $group->farm_id);
        $data = $request->validate(collect($this->groupRules())->except(['species_id', 'code'])->all() + ['status' => ['required', Rule::in(['active', 'closed'])]]);
        $field = $this->fieldOn($group->farm, $data['field_id'] ?? null);
        $group->update(['field_id' => $field?->id] + collect($data)->except('field_id')->all());

        return back()->with('success', 'Group updated.');
    }

    public function countChange(Request $request, LivestockGroup $group): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK);
        $this->assertWritable($request, $group->farm_id);
        $data = $request->validate([
            'changed_on' => ['required', 'date', 'before_or_equal:today'],
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', Rule::in(LivestockCountChange::REASONS)],
            'value' => ['nullable', 'numeric', 'gte:0'],
            'settled_by' => ['nullable', Rule::in(['bank', 'receivable'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->livestock->changeCount($group, (int) $data['delta'], $data['reason'], $data['changed_on'], $request->user(),
            $data['notes'] ?? null, isset($data['value']) ? (float) $data['value'] : null, $data['settled_by'] ?? 'receivable');

        return back()->with('success', 'Head count updated.');
    }

    public function storeAnimal(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'species_id' => ['required', 'integer'],
            'breed_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'string'],
            'field_id' => ['nullable', 'string'],
            'tag_number' => ['required', 'string', 'max:40'],
            'electronic_id' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:80'],
            'sex' => ['required', Rule::in(Animal::SEXES)],
            'birth_date' => ['nullable', 'date'],
            'acquisition' => ['required', Rule::in(Animal::ACQUISITIONS)],
            'acquired_on' => ['nullable', 'date', 'before_or_equal:today'],
            'acquisition_cost' => ['nullable', 'numeric', 'gte:0'],
            'dam_tag' => ['nullable', 'string', 'max:40'],
            'sire_tag' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $group = ! empty($data['group_id']) ? LivestockGroup::query()->where('uuid', $data['group_id'])->firstOrFail() : null;
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        $parent = fn (?string $tag) => $tag ? Animal::query()->where('species_id', $data['species_id'])->whereRaw('lower(tag_number) = ?', [mb_strtolower($tag)])->value('id') : null;

        $animal = $this->livestock->register($farm, [
            'group_id' => $group?->id, 'field_id' => $field?->id ?? $group?->field_id,
            'dam_id' => $parent($data['dam_tag'] ?? null), 'sire_id' => $parent($data['sire_tag'] ?? null),
        ] + collect($data)->except(['farm_id', 'group_id', 'field_id', 'dam_tag', 'sire_tag'])->all(), $request->user());

        return back()->with('success', "Animal {$animal->tag_number} registered.");
    }

    public function updateAnimal(Request $request, Animal $animal): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK);
        $this->assertWritable($request, $animal->farm_id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'electronic_id' => ['nullable', 'string', 'max:40'],
            'breed_id' => ['nullable', 'integer', Rule::exists('livestock_breeds', 'id')->where('species_id', $animal->species_id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $animal->update($data);

        return back()->with('success', 'Animal updated.');
    }

    public function move(Request $request, Animal $animal): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK);
        $this->assertWritable($request, $animal->farm_id);
        $data = $request->validate(['group_id' => ['nullable', 'string'], 'field_id' => ['nullable', 'string'],
            'moved_on' => ['required', 'date', 'before_or_equal:today'], 'reason' => ['nullable', 'string', 'max:300']]);
        $group = ! empty($data['group_id']) ? LivestockGroup::query()->where('uuid', $data['group_id'])->firstOrFail() : null;
        $this->livestock->move($animal, $group, $this->fieldOn($animal->farm, $data['field_id'] ?? null), $data['moved_on'], $data['reason'] ?? null, $request->user());

        return back()->with('success', "{$animal->tag_number} moved.");
    }

    public function exit(Request $request, Animal $animal): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK);
        $this->assertWritable($request, $animal->farm_id);
        $data = $request->validate([
            'status' => ['required', Rule::in(Animal::EXITS)],
            'exited_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:300'],
            'value' => ['nullable', 'numeric', 'gte:0'],
            'settled_by' => ['nullable', Rule::in(['bank', 'receivable'])],
        ]);
        $this->livestock->exit($animal, $data['status'], $data['exited_on'], $data['reason'] ?? null,
            isset($data['value']) ? (float) $data['value'] : null, $request->user(), $data['settled_by'] ?? 'receivable');

        return back()->with('success', "{$animal->tag_number} recorded as {$data['status']}.");
    }

    public function event(Request $request, Animal $animal): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK, R::RECORD_HEALTH);
        $this->assertWritable($request, $animal->farm_id);
        $data = $request->validate(['event_type' => ['required', Rule::in(array_diff(AnimalEvent::TYPES, ['exit']))],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'], 'outcome' => ['nullable', 'string', 'max:200']]);
        $this->livestock->event($animal, $data['event_type'], $data['occurred_on'], $request->user(), $data['outcome'] ?? null);

        return back()->with('success', 'Event recorded.');
    }

    public function treat(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_HEALTH);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'animal_id' => ['nullable', 'string'],
            'group_id' => ['nullable', 'string'],
            'event_type' => ['required', Rule::in(AnimalHealthRecord::TYPES)],
            'treated_on' => ['required', 'date'],
            'condition' => ['nullable', 'string', 'max:200'],
            'product_name' => ['nullable', 'string', 'max:150'],
            'stock_item_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'required_with:stock_item_id'],
            'dose' => ['nullable', 'string', 'max:60'],
            'head_count' => ['nullable', 'integer', 'min:1'],
            'withdrawal_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'next_due_on' => ['nullable', 'date', 'after:treated_on'],
            'vet_name' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $animal = ! empty($data['animal_id']) ? Animal::query()->where('uuid', $data['animal_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $group = ! empty($data['group_id']) ? LivestockGroup::query()->where('uuid', $data['group_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $this->livestock->treat($farm, $animal, $group, collect($data)->except(['farm_id', 'animal_id', 'group_id'])->all(), $request->user());

        return back()->with('success', 'Health record saved.');
    }

    public function weigh(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LIVESTOCK, R::RECORD_HEALTH);
        $data = $request->validate([
            'farm_id' => ['required', 'string'], 'animal_id' => ['nullable', 'string'], 'group_id' => ['nullable', 'string'],
            'weighed_on' => ['required', 'date', 'before_or_equal:today'], 'weight_kg' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'head_count' => ['nullable', 'integer', 'min:1'], 'condition_score' => ['nullable', 'numeric', 'between:1,9'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $animal = ! empty($data['animal_id']) ? Animal::query()->where('uuid', $data['animal_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $group = ! empty($data['group_id']) ? LivestockGroup::query()->where('uuid', $data['group_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        abort_if(! $animal && ! $group, 422, 'Choose the animal or group weighed.');
        $this->livestock->weigh($farm, $animal, $group, $data, $request->user());

        return back()->with('success', 'Weight recorded.');
    }

    // ── Shapes ───────────────────────────────────────────────────────────────────────────────

    private function groupRow(LivestockGroup $g): array
    {
        return ['id' => $g->uuid, 'code' => $g->code, 'name' => $g->name, 'farm' => $g->farm?->name, 'farm_id' => $g->farm?->uuid,
            'species' => $g->species?->name, 'species_id' => $g->species_id, 'tracking' => $g->species?->tracking,
            'purpose' => $g->purpose, 'field' => $g->field?->code, 'field_id' => $g->field?->uuid, 'status' => $g->status,
            'head' => $g->species?->tracking === 'group' ? (int) $g->head_count : (int) ($g->active_animals ?? $g->currentHeadCount()),
            'started_on' => $g->started_on?->toDateString()];
    }

    private function animalRow(Animal $a): array
    {
        return ['id' => $a->uuid, 'tag_number' => $a->tag_number, 'name' => $a->name, 'farm' => $a->farm?->name, 'farm_id' => $a->farm?->uuid,
            'species' => $a->species?->name, 'species_id' => $a->species_id, 'breed' => $a->breed?->name, 'breed_id' => $a->breed_id,
            'group' => $a->group?->code, 'group_id' => $a->group?->uuid, 'field' => $a->field?->code, 'sex' => $a->sex,
            'birth_date' => $a->birth_date?->toDateString(), 'age_months' => $a->birth_date ? (int) $a->birth_date->diffInMonths(today()) : null,
            'status' => $a->status, 'exited_on' => $a->exited_on?->toDateString(), 'last_weight_kg' => self::num($a->last_weight_kg)];
    }

    private function healthRow(AnimalHealthRecord $h): array
    {
        return ['id' => $h->uuid, 'farm' => $h->farm?->name, 'animal' => $h->animal?->tag_number, 'animal_id' => $h->animal?->uuid,
            'group' => $h->group?->code, 'event_type' => $h->event_type, 'treated_on' => $h->treated_on->toDateString(),
            'condition' => $h->condition, 'product_name' => $h->product_name, 'dose' => $h->dose, 'head_count' => $h->head_count,
            'withdrawal_until' => $h->withdrawal_until?->toDateString(), 'next_due_on' => $h->next_due_on?->toDateString(),
            'vet_name' => $h->vet_name, 'cost' => self::num($h->cost),
            'in_withdrawal' => $h->withdrawal_until !== null && $h->withdrawal_until->gt(today())];
    }

    private function groupRules(): array
    {
        return [
            'species_id' => ['required', 'integer'],
            'field_id' => ['nullable', 'string'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/'],
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', Rule::in(LivestockGroup::PURPOSES)],
            'budget_amount' => ['nullable', 'numeric', 'gte:0'],
            'started_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function options(): array
    {
        return [
            'farms' => $this->farmOptions(),
            'species' => LivestockSpecies::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'tracking']),
            'breeds' => LivestockBreed::query()->where('is_active', true)->orderBy('name')->get(['id', 'species_id', 'name']),
            'groups' => LivestockGroup::query()->where('status', 'active')->with('farm:id,uuid')->orderBy('code')->get()
                ->map(fn ($g) => ['id' => $g->uuid, 'code' => $g->code, 'name' => $g->name, 'farm_id' => $g->farm?->uuid, 'species_id' => $g->species_id]),
            'animals' => Animal::query()->where('status', Animal::ACTIVE)->with('farm:id,uuid')->orderBy('tag_number')->limit(1000)->get(['id', 'uuid', 'farm_id', 'tag_number', 'species_id', 'sex'])
                ->map(fn ($a) => ['id' => $a->uuid, 'tag_number' => $a->tag_number, 'farm_id' => $a->farm?->uuid, 'species_id' => $a->species_id, 'sex' => $a->sex]),
            'stock_items' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'uom'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => "{$i->name} ({$i->code})", 'uom' => $i->uom]),
            'purposes' => LivestockGroup::PURPOSES,
            'sexes' => Animal::SEXES,
            'acquisitions' => Animal::ACQUISITIONS,
            'exits' => Animal::EXITS,
            'health_types' => AnimalHealthRecord::TYPES,
            'event_types' => array_values(array_diff(AnimalEvent::TYPES, ['exit'])),
            'count_reasons' => LivestockCountChange::REASONS,
        ];
    }

    private function can(Request $request): array
    {
        $u = $request->user();

        return ['manage' => $u->can(R::MANAGE_LIVESTOCK), 'health' => $u->can(R::RECORD_HEALTH)];
    }
}
