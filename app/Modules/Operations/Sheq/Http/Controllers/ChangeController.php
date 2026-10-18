<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Services\ChangeImpactDetector;
use App\Modules\Operations\Sheq\Services\ChangeManagementService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Management of change (MOC-1 to MOC-5).
 *
 * The register and the open change on one screen, because they are read together: the list says
 * three changes are waiting and the detail says what each is waiting FOR, and the second is the
 * only part anybody can act on.
 *
 * The refusals here are deliberately verbose. `implement` can fail for half a dozen reasons at
 * once — an approval not granted, four mandatory controls outstanding — and a message that says
 * only "cannot be implemented" is how somebody makes the change anyway and records it afterwards,
 * which is precisely the behaviour this feature exists to replace.
 */
class ChangeController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(private readonly ChangeManagementService $changes) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $selected = $request->query('change');

        $detail = null;

        if ($companyId !== null && $selected !== null) {
            try {
                $detail = $this->changes->detail($companyId, $selected);
            } catch (RuntimeException) {
                // A stale link rather than an error worth a page: the register is still useful.
                $detail = null;
            }
        }

        return Inertia::render('Modules/Sheq/Registers/Changes', [
            'noCompany' => $companyId === null,
            'rows' => $companyId === null ? [] : $this->changes->all($companyId, [
                'status' => $request->query('status'),
                'site_id' => $this->permittedSite($request, $companyId),
            ]),
            'detail' => $detail,
            'categories' => collect(ChangeManagementService::CATEGORIES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()->all(),
            'natures' => collect(ChangeManagementService::NATURES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()->all(),
            'riskRatings' => ChangeManagementService::RISK_RATINGS,
            /*
             * The eight impact kinds with their notes, so the screen can explain why each is
             * asked about. A checklist of eight categories with no explanation gets cleared
             * rather than answered.
             */
            'impactTypes' => collect(ChangeImpactDetector::IMPACT_TYPES)
                ->map(fn (array $meta, string $key) => $meta + ['key' => $key])->values()->all(),
            'categoryImpacts' => ChangeImpactDetector::CATEGORY_IMPACTS,
            'statuses' => [ChangeManagementService::DRAFT, ChangeManagementService::SUBMITTED,
                ChangeManagementService::APPROVED, ChangeManagementService::IMPLEMENTED,
                ChangeManagementService::REVIEWED, ChangeManagementService::REJECTED],
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'contractors' => $companyId === null ? [] : DB::table('sheq_contractors')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name'])->map(fn ($c) => (array) $c)->all(),
            'people' => $companyId === null ? []
                : app(EmployeeDirectory::class)->roster($companyId),
            'canRequest' => (bool) $request->user()?->can('sheq.hazard.report'),
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'change_category' => ['required',
                Rule::in(array_keys(ChangeManagementService::CATEGORIES))],
            'change_nature' => ['nullable',
                Rule::in(array_keys(ChangeManagementService::NATURES))],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'contractor_id' => ['nullable', 'integer', Rule::exists('sheq_contractors', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'risk_rating' => ['nullable', Rule::in(ChangeManagementService::RISK_RATINGS)],
            'impact_summary' => ['nullable', 'string', 'max:4000'],
            'planned_date' => ['nullable', 'date'],
        ]);

        $created = $this->changes->register($companyId, $data, $request->user()?->id);

        return redirect('/sheq/changes?change='.$created['uuid'])->with('success', sprintf(
            '%s registered. %d possible knock-on effects were found — go through them and mark '
            .'each one dealt with or not applicable.',
            $created['reference'], $created['impacts']));
    }

    /** Submit for approval, routed by risk (MOC-3). */
    public function submit(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        try {
            $result = $this->changes->submit($this->requireCompany($request), $uuid,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['change' => $e->getMessage()]);
        }

        return back()->with('success', $result['approval_opened']
            ? sprintf('%s sent for approval.', $result['reference'])
            /*
             * Said plainly rather than reported as a success. A tenant with no workflow for this
             * risk band has the change advance with nobody having approved it, and somebody has
             * to know that — otherwise the absence of an approver reads as an approval.
             */
            : sprintf('%s submitted. No approval workflow is configured for a %s change here, '
                .'so nobody has been asked to approve it.', $result['reference'],
                strtolower((string) $this->changes->find($this->requireCompany($request), $uuid)
                    ->risk_rating)));
    }

    public function addImpact(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $data = $request->validate([
            'impact_type' => ['required',
                Rule::in(array_keys(ChangeImpactDetector::IMPACT_TYPES))],
            'subject_title' => ['required', 'string', 'max:255'],
            'is_mandatory' => ['nullable', 'boolean'],
            'assigned_to' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
        ]);

        try {
            $this->changes->addImpact($this->requireCompany($request), $uuid, $data,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['impact' => $e->getMessage()]);
        }

        return back()->with('success', 'Added to the list.');
    }

    public function decideImpact(Request $request, string $impactUuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $data = $request->validate([
            'status' => ['required', Rule::in(['OUTSTANDING', 'DONE', 'NOT_APPLICABLE'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->changes->decideImpact($this->requireCompany($request), $impactUuid,
                $data['status'], $data['note'] ?? null, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['impact' => $e->getMessage()]);
        }

        return back()->with('success', 'Recorded.');
    }

    /** MOC-4's gate. */
    public function implement(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        try {
            $this->changes->implement($this->requireCompany($request), $uuid,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['change' => $e->getMessage()]);
        }

        return back()->with('success',
            'Recorded as implemented. The effectiveness review has been diarised.');
    }

    /** MOC-5. */
    public function review(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $data = $request->validate([
            'effectiveness_result' => ['required',
                Rule::in(['EFFECTIVE', 'PARTIALLY_EFFECTIVE', 'NOT_EFFECTIVE'])],
            'effectiveness_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $this->changes->review($this->requireCompany($request), $uuid, $data,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back()->with('success', 'Review recorded.');
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $this->changes->reject($this->requireCompany($request), $uuid, $data['reason'],
            $request->user()?->id);

        return back()->with('success', 'Change rejected.');
    }
}
