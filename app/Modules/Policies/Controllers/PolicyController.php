<?php

namespace App\Modules\Policies\Controllers;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\AI\Registry\AgentRegistry;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Policies\Jobs\ExtractPolicyClausesJob;
use App\Modules\Policies\Requests\Policy\StorePolicyRequest;
use App\Modules\Policies\Requests\Policy\UpdatePolicyRequest;
use App\Modules\Policies\Services\PolicyService;
use App\Modules\Policies\Models\PolicyCategory;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
class PolicyController extends Controller
{
    protected PolicyService $policyService;

    public function __construct(PolicyService $policyService)
    {
        $this->policyService = $policyService;
    }

    /**
     * List all policies
     */
    public function index(Request $request)
    {
        $filters = $request->only(['status', 'category_id', 'department_id', 'search']);
        $policies = $this->policyService->getAll($filters);
        $modal = (string) $request->query('modal', '');
        $editPolicyUuid = (string) $request->query('edit_policy_uuid', '');

        $categories = PolicyCategory::select('id', 'name')->get();
        $owners = $this->userOptions();
        $reviewers = $owners;
        $editPolicy = null;

        if ($modal === 'edit' && $editPolicyUuid !== '') {
            $editPolicy = Policy::query()
                ->where('uuid', $editPolicyUuid)
                ->with(['reviewers'])
                ->first();
        }

        return Inertia::render('Modules/Policies/Index', [
            'policies' => $policies,
            'filters' => $filters,
            'categories' => $categories,
            'owners' => $owners,
            'reviewers' => $reviewers,
            'companyUnits' => $this->companyUnitsOptions(),
            'ui' => [
                'modal' => $modal,
                'edit_policy_id' => null,
            ],
            'editPolicy' => $editPolicy,
        ]);
    }

    /**
     * Show form to create a new policy
     */
    public function create()
    {
        $categories = PolicyCategory::select('id', 'name')->get();
        $owners = $this->userOptions();

        return Inertia::render('Modules/Policies/Create', [
            'categories' => $categories,
            'owners' => $owners,
        ]);
    }

    /**
     * Store a new policy
     */
    public function store(StorePolicyRequest $request)
    {
        $policy = $this->policyService->create($request->validated());

        return redirect()->route('policies.index')
            ->with('success', 'Policy created successfully.');
    }

    /**
     * Show a single policy (read-only)
     */
    public function show(Policy $policy)
    {
        $users = $this->userOptions(includeEmail: true);
        $owners = $this->userOptions();
        $reviewers = $owners;
        $categories = PolicyCategory::select('id', 'name')->get();

        return Inertia::render('Modules/Policies/Show', [
            'policy' => $policy->load(['category', 'owner', 'clauses', 'rules', 'reviewers.user', 'versions.creator', 'documents']),
            'users' => $users,
            'owners' => $owners,
            'reviewers' => $reviewers,
            'categories' => $categories,
            'companyUnits' => $this->companyUnitsOptions(),
        ]);
    }

    /**
     * Show form to edit a policy
     */
    public function edit(Policy $policy)
    {
        $categories = PolicyCategory::select('id', 'name')->get();
        $owners = $this->userOptions();
        $reviewers = $owners;

        return Inertia::render('Modules/Policies/Edit', [
            'policy' => $policy->load(['reviewers']),
            'categories' => $categories,
            'owners' => $owners,
            'reviewers' => $reviewers,
            'companyUnits' => $this->companyUnitsOptions(),
        ]);
    }

    /**
     * Update a policy
     */
    public function update(UpdatePolicyRequest $request, Policy $policy)
    {
        $updated = $this->policyService->update($policy->id, $request->validated());
        if (!$updated) {
            return redirect()->back()
                ->with('error', 'Policy not found or update failed.');
        }

        if ($request->input('redirect_to') === 'index') {
            return redirect()->route('policies.index')
                ->with('success', 'Policy updated successfully.');
        }

        return redirect()->route('policies.show', $policy)
            ->with('success', 'Policy updated successfully.');
    }

    /**
     * Delete a policy
     */
    public function destroy(Policy $policy)
    {
        $deleted = $this->policyService->delete($policy->id);
        if (!$deleted) {
            return redirect()->back()
                ->with('error', 'Policy not found.');
        }

        return redirect()->route('policies.index')
            ->with('success', 'Policy deleted successfully.');
    }

    /**
     * Upload a document and attach it to a policy.
     */
    public function uploadDocument(Request $request, Policy $policy): JsonResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        $path = $this->policyService->uploadDocument($request->file('document'));
        $policy->update(['document' => $path]);

        return response()->json(['document' => $path, 'message' => 'Document uploaded successfully.']);
    }

    public function viewDocument(Policy $policy, int $document): Response
    {
        $doc = PolicyDocument::where('policy_id', $policy->id)->findOrFail($document);

        return $this->policyService->buildDocumentResponse($doc, true);
    }

    public function downloadDocument(Policy $policy, int $document): Response
    {
        $doc = PolicyDocument::where('policy_id', $policy->id)->findOrFail($document);

        return $this->policyService->buildDocumentResponse($doc, false);
    }

    public function extractDocument(Request $request, Policy $policy, int $document, AgentRegistry $agents): JsonResponse|RedirectResponse
    {
        $doc = PolicyDocument::where('policy_id', $policy->id)->findOrFail($document);

        (new ExtractPolicyClausesJob($doc))->handle($agents);

        if ($request->header('X-Inertia')) {
            return redirect()
                ->route('policies.clauses.index', $policy)
                ->with('success', 'AI extraction complete.');
        }

        return response()->json([
            'message' => 'AI extraction complete.',
            'document' => [
                'id' => $doc->id,
                'extraction_status' => $doc->fresh()->extraction_status,
            ],
        ]);
    }

    private function userOptions(bool $includeEmail = false)
    {
        return User::query()
            ->with('person:id,user_id,first_name,surname')
            ->select('id', 'email')
            ->orderBy('email')
            ->get()
            ->map(function (User $user) use ($includeEmail) {
                $name = trim(($user->person->first_name ?? '') . ' ' . ($user->person->surname ?? ''));

                $payload = [
                    'id' => $user->id,
                    'name' => $name !== '' ? $name : ($user->email ?? ('User #' . $user->id)),
                ];

                if ($includeEmail) {
                    $payload['email'] = $user->email;
                }

                return $payload;
            })
            ->values();
    }

    private function companyUnitsOptions(): array
    {
        $baseSelect = ['id', 'name', 'parent_id', 'type'];

        return [
            'divisions' => CompanyUnit::where('type', 'division')->orderBy('name')->get($baseSelect),
            'departments' => CompanyUnit::where('type', 'department')->orderBy('name')->get($baseSelect),
            'sections' => CompanyUnit::where('type', 'section')->orderBy('name')->get($baseSelect),
            'teams' => CompanyUnit::where('type', 'team')->orderBy('name')->get($baseSelect),
            'units' => CompanyUnit::where('type', 'unit')->orderBy('name')->get($baseSelect),
        ];
    }
}
