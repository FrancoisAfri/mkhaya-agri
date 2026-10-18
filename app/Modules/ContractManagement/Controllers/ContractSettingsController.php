<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractCategory;
use App\Modules\ContractManagement\Models\ContractsTemplate;
use App\Modules\ContractManagement\Models\ContractType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/*
 * The flashes are i18n keys, not sentences: module screens have no PHP catalogue, so MainLayout
 * translates a flashed key client-side. A sentence here would have been English in all twelve
 * languages. See tests/Feature/Localisation/FlashedKeyTest.
 */
class ContractSettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize('update', Contract::class);

        return Inertia::render('Modules/ContractManagement/Settings/Index', [
            'types' => ContractType::orderBy('name')->get(['id', 'name', 'description', 'status']),
            'categories' => ContractCategory::orderBy('name')->get(['id', 'name', 'description', 'status']),
            'templates' => ContractsTemplate::orderBy('name')->get(['id', 'name', 'status']),
        ]);
    }

    // ─── Contract Types ────────────────────────────────────────────────────────

    public function typesStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        ContractType::create($data);

        return back()->with('success', 'contracts_settings.flash.type_created');
    }

    public function typesUpdate(Request $request, ContractType $type): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        $type->update($data);

        return back()->with('success', 'contracts_settings.flash.type_updated');
    }

    public function typesDestroy(ContractType $type): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $type->delete();

        return back()->with('success', 'contracts_settings.flash.type_deleted');
    }

    // ─── Contract Categories ───────────────────────────────────────────────────

    public function categoriesStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        ContractCategory::create($data);

        return back()->with('success', 'contracts_settings.flash.category_created');
    }

    public function categoriesUpdate(Request $request, ContractCategory $category): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        $category->update($data);

        return back()->with('success', 'contracts_settings.flash.category_updated');
    }

    public function categoriesDestroy(ContractCategory $category): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $category->delete();

        return back()->with('success', 'contracts_settings.flash.category_deleted');
    }

    // ─── Contract Templates ────────────────────────────────────────────────────

    public function templatesStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        $data['owner_id'] = Auth::id();

        ContractsTemplate::create($data);

        return back()->with('success', 'contracts_settings.flash.template_created');
    }

    public function templatesUpdate(Request $request, ContractsTemplate $template): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        $template->update($data);

        return back()->with('success', 'contracts_settings.flash.template_updated');
    }

    public function templatesDestroy(ContractsTemplate $template): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $template->delete();

        return back()->with('success', 'contracts_settings.flash.template_deleted');
    }
}
