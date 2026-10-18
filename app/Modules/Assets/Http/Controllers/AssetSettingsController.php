<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Modules\Assets\Enums\DepreciationMethod;
use App\Modules\Assets\Enums\SarsAllowanceSection;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\AssetMake;
use App\Modules\Assets\Models\AssetModel;
use App\Modules\Assets\Models\AssetSetting;
use App\Modules\Assets\Models\AssetType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssetSettingsController
{
    // ── General Settings ───────────────────────────────────────────────────────

    public function index(): Response
    {
        return Inertia::render('Modules/Assets/Settings/Index', [
            'settings' => [
                'asset_number_prefix'      => AssetSetting::get('asset_number_prefix', 'AST'),
                'asset_number_format'      => AssetSetting::get('asset_number_format', '{PREFIX}-{YEAR}-{SEQ:5}'),
                'financial_year_start'     => AssetSetting::get('financial_year_start', '03-01'),
                'depreciation_run'         => AssetSetting::get('depreciation_run', 'monthly'),
                'approval_levels'          => AssetSetting::get('approval_levels', 2),
                'approval_threshold_l2'    => AssetSetting::get('approval_threshold_l2', 10000),
                'approval_threshold_l3'    => AssetSetting::get('approval_threshold_l3', 50000),
                'enable_dual_depreciation' => AssetSetting::get('enable_dual_depreciation', false),
                'enable_impairment'        => AssetSetting::get('enable_impairment', false),
                'enable_lease_rou'         => AssetSetting::get('enable_lease_rou', false),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'asset_number_prefix'      => 'nullable|string|max:10',
            'asset_number_format'      => 'nullable|string|max:60',
            'financial_year_start'     => 'nullable|string|max:5',
            'depreciation_run'         => 'nullable|in:monthly,quarterly,annual',
            'approval_levels'          => 'nullable|integer|min:1|max:5',
            'approval_threshold_l2'    => 'nullable|numeric|min:0',
            'approval_threshold_l3'    => 'nullable|numeric|min:0',
            'enable_dual_depreciation' => 'boolean',
            'enable_impairment'        => 'boolean',
            'enable_lease_rou'         => 'boolean',
        ]);

        AssetSetting::set('asset_number_prefix',      $data['asset_number_prefix']      ?? 'AST');
        AssetSetting::set('asset_number_format',      $data['asset_number_format']       ?? '{PREFIX}-{YEAR}-{SEQ:5}');
        AssetSetting::set('financial_year_start',     $data['financial_year_start']      ?? '03-01');
        AssetSetting::set('depreciation_run',         $data['depreciation_run']          ?? 'monthly');
        AssetSetting::set('approval_levels',          $data['approval_levels']           ?? 2, 'integer');
        AssetSetting::set('approval_threshold_l2',    $data['approval_threshold_l2']     ?? 10000, 'float');
        AssetSetting::set('approval_threshold_l3',    $data['approval_threshold_l3']     ?? 50000, 'float');
        AssetSetting::set('enable_dual_depreciation', $data['enable_dual_depreciation']  ?? false, 'boolean');
        AssetSetting::set('enable_impairment',        $data['enable_impairment']         ?? false, 'boolean');
        AssetSetting::set('enable_lease_rou',         $data['enable_lease_rou']          ?? false, 'boolean');

        return back()->with('success', 'Settings saved.');
    }

    // ── Categories ─────────────────────────────────────────────────────────────

    public function categoriesIndex(): Response
    {
        $categories = AssetCategory::with('parent')
            ->withCount(['children', 'assets'])
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id'                          => $c->id,
                'uuid'                        => $c->uuid,
                'name'                        => $c->name,
                'description'                 => $c->description,
                'parent_id'                   => $c->parent_id,
                'parent_name'                 => $c->parent?->name,
                'sars_allowance_section'      => $c->sars_allowance_section?->value,
                'default_depreciation_method' => $c->default_depreciation_method?->value,
                'default_useful_life_years'   => $c->default_useful_life_years,
                'sars_wear_rate'              => $c->sars_wear_rate,
                'status'                      => $c->status,
                'children_count'              => $c->children_count,
                'assets_count'                => $c->assets_count,
            ]);

        return Inertia::render('Modules/Assets/Settings/Categories', [
            'categories'          => $categories,
            'depreciation_methods' => collect(DepreciationMethod::cases())->map(fn ($e) => ['value' => $e->value, 'label' => $e->label()]),
            'sars_sections'       => collect(SarsAllowanceSection::cases())->map(fn ($e) => ['value' => $e->value, 'label' => $e->label()]),
        ]);
    }

    public function categoriesStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                        => 'required|string|max:120',
            'description'                 => 'nullable|string|max:500',
            'parent_id'                   => 'nullable|integer|exists:asset_categories,id',
            'sars_allowance_section'      => ['nullable', Rule::enum(SarsAllowanceSection::class)],
            'default_depreciation_method' => ['nullable', Rule::enum(DepreciationMethod::class)],
            'default_useful_life_years'   => 'nullable|integer|min:1|max:100',
            'sars_wear_rate'              => 'nullable|numeric|min:0|max:1',
            'status'                      => 'boolean',
        ]);

        AssetCategory::create($data);

        return back()->with('success', 'Category created.');
    }

    public function categoriesUpdate(Request $request, AssetCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name'                        => 'required|string|max:120',
            'description'                 => 'nullable|string|max:500',
            'parent_id'                   => ['nullable', 'integer', 'exists:asset_categories,id', Rule::notIn([$category->id])],
            'sars_allowance_section'      => ['nullable', Rule::enum(SarsAllowanceSection::class)],
            'default_depreciation_method' => ['nullable', Rule::enum(DepreciationMethod::class)],
            'default_useful_life_years'   => 'nullable|integer|min:1|max:100',
            'sars_wear_rate'              => 'nullable|numeric|min:0|max:1',
            'status'                      => 'boolean',
        ]);

        $category->update($data);

        return back()->with('success', 'Category updated.');
    }

    public function categoriesDestroy(AssetCategory $category): RedirectResponse
    {
        if ($category->assets()->exists()) {
            return back()->withErrors(['delete' => 'Cannot delete a category with assigned assets.']);
        }

        if ($category->children()->exists()) {
            return back()->withErrors(['delete' => 'Cannot delete a category that still has sub-categories.']);
        }

        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    // ── Types ──────────────────────────────────────────────────────────────────

    public function typesIndex(): Response
    {
        $types = AssetType::orderBy('name')->get()->map(fn ($t) => [
            'id'          => $t->id,
            'name'        => $t->name,
            'description' => $t->description,
            'status'      => $t->status,
        ]);

        return Inertia::render('Modules/Assets/Settings/Types', [
            'types' => $types,
        ]);
    }

    public function typesStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'status'      => 'boolean',
        ]);

        AssetType::create($data);

        return back()->with('success', 'Asset type created.');
    }

    public function typesUpdate(Request $request, AssetType $type): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'status'      => 'boolean',
        ]);

        $type->update($data);

        return back()->with('success', 'Asset type updated.');
    }

    public function typesDestroy(AssetType $type): RedirectResponse
    {
        if ($type->assets()->exists()) {
            return back()->withErrors(['delete' => 'Cannot delete a type with assigned assets.']);
        }

        $type->delete();

        return back()->with('success', 'Asset type deleted.');
    }

    // ── Locations ──────────────────────────────────────────────────────────────

    public function locationsIndex(): Response
    {
        $flat = AssetLocation::with('parent')
            ->withCount('assets')
            ->orderBy('name')
            ->get()
            ->map(fn ($l) => [
                'id'          => $l->id,
                'uuid'        => $l->uuid,
                'name'        => $l->name,
                'code'        => $l->code,
                'parent_id'   => $l->parent_id,
                'parent_name' => $l->parent?->name,
                'address'     => $l->address,
                'status'      => $l->status,
                'assets_count' => $l->assets_count,
            ]);

        return Inertia::render('Modules/Assets/Settings/Locations', [
            'locations' => $flat,
        ]);
    }

    public function locationsStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'code'      => 'nullable|string|max:20',
            'parent_id' => 'nullable|integer|exists:asset_locations,id',
            'address'   => 'nullable|string|max:500',
            'status'    => 'boolean',
        ]);

        AssetLocation::create($data);

        return back()->with('success', 'Location created.');
    }

    public function locationsUpdate(Request $request, AssetLocation $location): RedirectResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'code'      => 'nullable|string|max:20',
            'parent_id' => ['nullable', 'integer', 'exists:asset_locations,id', Rule::notIn([$location->id])],
            'address'   => 'nullable|string|max:500',
            'status'    => 'boolean',
        ]);

        $location->update($data);

        return back()->with('success', 'Location updated.');
    }

    public function locationsDestroy(AssetLocation $location): RedirectResponse
    {
        if ($location->assets()->exists()) {
            return back()->withErrors(['delete' => 'Cannot delete a location with assigned assets.']);
        }

        $location->delete();

        return back()->with('success', 'Location deleted.');
    }

    // ── Makes & Models ─────────────────────────────────────────────────────────

    public function makesIndex(): Response
    {
        $makes = AssetMake::withCount(['models', 'assets'])
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id'           => $m->id,
                'uuid'         => $m->uuid,
                'name'         => $m->name,
                'status'       => $m->status,
                'models_count' => $m->models_count,
                'assets_count' => $m->assets_count,
            ]);

        $models = AssetModel::with('make')
            ->withCount('assets')
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id'           => $m->id,
                'uuid'         => $m->uuid,
                'make_id'      => $m->make_id,
                'make_name'    => $m->make?->name,
                'name'         => $m->name,
                'status'       => $m->status,
                'assets_count' => $m->assets_count,
            ]);

        return Inertia::render('Modules/Assets/Settings/Makes', [
            'makes'  => $makes,
            'models' => $models,
        ]);
    }

    public function makesStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'   => 'required|string|max:120|unique:asset_makes,name',
            'status' => 'boolean',
        ]);

        AssetMake::create($data);

        return back()->with('success', 'Make created.');
    }

    public function makesUpdate(Request $request, AssetMake $make): RedirectResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:120', Rule::unique('asset_makes', 'name')->ignore($make->id)],
            'status' => 'boolean',
        ]);

        $make->update($data);

        return back()->with('success', 'Make updated.');
    }

    public function makesDestroy(AssetMake $make): RedirectResponse
    {
        if ($make->assets()->exists()) {
            return back()->withErrors(['delete' => 'Cannot delete a make with assigned assets.']);
        }

        if ($make->models()->exists()) {
            return back()->withErrors(['delete' => 'Cannot delete a make that still has models. Delete the models first.']);
        }

        $make->delete();

        return back()->with('success', 'Make deleted.');
    }

    public function modelsStore(Request $request, AssetMake $make): RedirectResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:120', Rule::unique('asset_models', 'name')->where('make_id', $make->id)],
            'status' => 'boolean',
        ]);

        $make->models()->create($data);

        return back()->with('success', 'Model created.');
    }

    public function modelsUpdate(Request $request, AssetModel $model): RedirectResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:120', Rule::unique('asset_models', 'name')->where('make_id', $model->make_id)->ignore($model->id)],
            'status' => 'boolean',
        ]);

        $model->update($data);

        return back()->with('success', 'Model updated.');
    }

    public function modelsDestroy(AssetModel $model): RedirectResponse
    {
        if ($model->assets()->exists()) {
            return back()->withErrors(['delete' => 'Cannot delete a model with assigned assets.']);
        }

        $model->delete();

        return back()->with('success', 'Model deleted.');
    }
}
