<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ContractTemplate;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\ContractTemplateService;
use App\Modules\Operations\ProjectManagement\Support\ContractBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project Management's own contract template library.
 *
 * Deliberately not a link to Contract Management. The modules are sold separately, so a tenant on
 * Project Management alone has to be able to write and keep the wording they subcontract on. Where
 * they own both, Contract Management becomes something to import from — a copy, taken once.
 *
 * Administration, so the same `manageSettings` gate as calendars and approval flows: a project
 * manager drafts *from* these but does not get to rewrite the company's standard terms.
 */
class ContractTemplateController extends Controller
{
    public function __construct(private readonly ContractTemplateService $templates)
    {
    }

    public function index(): Response
    {
        $this->authorize('manageSettings', Project::class);

        return Inertia::render('Modules/ProjectManagement/Contracts/Templates', [
            'templates'  => $this->templates->all(),
            // Only offered where Contract Management is actually part of the plan. PM's own rule:
            // an integration's absence is a missing button, never a broken one.
            'canImport'  => $this->templates->canImport(),
            'importable' => $this->templates->importable(),
        ]);
    }

    /**
     * One template's wording, as JSON.
     *
     * Fetched only when a template is opened rather than shipped with the list: a library of
     * thirty agreements is a couple of megabytes of body text the index never shows. Plain JSON,
     * not an Inertia page — the editor wants the value, not a navigation.
     */
    public function show(string $uuid): JsonResponse
    {
        $this->authorize('manageSettings', Project::class);

        $template = ContractTemplate::where('uuid', $uuid)->firstOrFail();

        return response()->json(['body' => ContractBody::sanitize($template->body)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $this->templates->create($this->validated($request));

        return back()->with('success', __('project_management.flash.contract_template_saved'));
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $template = ContractTemplate::where('uuid', $uuid)->firstOrFail();

        $this->templates->update($template, $this->validated($request));

        return back()->with('success', __('project_management.flash.contract_template_saved'));
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $template = ContractTemplate::where('uuid', $uuid)->firstOrFail();

        $this->templates->delete($template);

        return back()->with('success', __('project_management.flash.contract_template_deleted'));
    }

    /** Copy one of Contract Management's templates into this library. */
    public function import(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $data = $request->validate(['source' => ['required', 'string', 'max:64']]);

        $this->templates->import($data['source']);

        return back()->with('success', __('project_management.flash.contract_template_imported'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name'      => ['required', 'string', 'max:190'],
            'summary'   => ['nullable', 'string', 'max:500'],
            'category'  => ['nullable', 'string', 'max:80'],
            'body'      => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
