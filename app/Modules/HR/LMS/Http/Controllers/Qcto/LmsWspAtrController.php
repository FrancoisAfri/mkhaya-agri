<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\Qcto\LmsAtrReport;
use App\Modules\HR\LMS\Models\Qcto\LmsSdf;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnership;
use App\Modules\HR\LMS\Models\Qcto\LmsWspItem;
use App\Modules\HR\LMS\Models\Qcto\LmsWspItemDemographic;
use App\Modules\HR\LMS\Models\Qcto\LmsWspPlan;
use App\Modules\HR\LMS\Services\Qcto\LmsWspAtrService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LmsWspAtrController extends Controller
{
    public function __construct(private readonly LmsWspAtrService $service) {}

    public function index()
    {
        $wspPlans = LmsWspPlan::with(['seta', 'sdf', 'atrReport'])
            ->withCount('items')
            ->orderByDesc('plan_year')
            ->get();

        $setas = LmsSeta::active()->orderBy('acronym')->get(['id','name','acronym']);
        $sdfs  = LmsSdf::active()->with('user')->get();

        return Inertia::render('Modules/LMS/Admin/WspAtr', [
            'wspPlans' => $wspPlans,
            'setas'    => $setas,
            'sdfs'     => $sdfs,
        ]);
    }

    public function show(LmsWspPlan $wspPlan)
    {
        $wspPlan->load(['seta', 'sdf.user.person', 'items.course', 'items.learnership', 'items.demographics', 'atrReport.items.hrPerson']);

        $courses      = LmsCourse::where('is_published', true)->orderBy('title')
            ->get(['id', 'title', 'nqf_level', 'credits', 'ofo_code', 'saqa_id']);
        $learnerships = LmsLearnership::where('is_active', true)->orderBy('title')
            ->get(['id', 'title', 'nqf_level', 'total_credits', 'ofo_code', 'saqa_id']);

        return Inertia::render('Modules/LMS/Admin/WspAtrDetail', [
            'plan'         => $wspPlan,
            'courses'      => $courses,
            'learnerships' => $learnerships,
        ]);
    }

    // ── WSP CRUD ───────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_year'                   => 'required|integer|min:2020|max:2040',
            'seta_id'                     => 'nullable|exists:lms_setas,id',
            'sdf_id'                      => 'nullable|exists:lms_sdfs,id',
            'due_date'                    => 'nullable|date',
            'total_levy_paid'             => 'nullable|numeric|min:0',
            'mandatory_grant_claimed'     => 'boolean',
            'discretionary_grant_applied' => 'boolean',
            'sdl_number'                  => 'nullable|string|max:30',
            'paye_reference'              => 'nullable|string|max:30',
            'sic_code'                    => 'nullable|string|max:10',
            'notes'                       => 'nullable|string',
        ]);

        $data['created_by'] = auth()->id();
        $plan = LmsWspPlan::create($data);

        return back()->with('success', 'WSP plan created.');
    }

    public function update(Request $request, LmsWspPlan $wspPlan)
    {
        $data = $request->validate([
            'seta_id'                     => 'nullable|exists:lms_setas,id',
            'sdf_id'                      => 'nullable|exists:lms_sdfs,id',
            'due_date'                    => 'nullable|date',
            'submission_date'             => 'nullable|date',
            'status'                      => 'nullable|in:draft,submitted,approved,rejected,resubmit',
            'total_levy_paid'             => 'nullable|numeric|min:0',
            'total_planned_spend'         => 'nullable|numeric|min:0',
            'mandatory_grant_claimed'     => 'boolean',
            'discretionary_grant_applied' => 'boolean',
            'submission_reference'        => 'nullable|string|max:80',
            'sdl_number'                  => 'nullable|string|max:30',
            'paye_reference'              => 'nullable|string|max:30',
            'sic_code'                    => 'nullable|string|max:10',
            'notes'                       => 'nullable|string',
        ]);

        $wspPlan->update($data);

        return back()->with('success', 'WSP updated.');
    }

    // ── WSP Items ──────────────────────────────────────────────────────────────

    public function storeItem(Request $request, LmsWspPlan $wspPlan)
    {
        $validDemoKeys = array_keys(LmsWspAtrService::WSP_DEMOGRAPHIC_COLUMNS);

        $data = $request->validate([
            'course_id'              => 'nullable|exists:lms_courses,id',
            'learnership_id'         => 'nullable|exists:lms_learnerships,id',
            'position_id'            => 'nullable|exists:hr_positions,id',
            'training_type'          => 'required|string|max:40',
            'training_description'   => 'nullable|string|max:300',
            'nqf_level'              => 'nullable|integer|min:1|max:10',
            'credits'                => 'nullable|numeric|min:0',
            'ofo_code'               => 'nullable|string|max:10',
            'saqa_id'                => 'nullable|string|max:20',
            'planned_cost_per_person'=> 'required|numeric|min:0',
            'demographics'           => 'nullable|array',
            'demographics.*'         => 'integer|min:0',
        ]);

        // Derive total planned beneficiaries from demographic breakdown when supplied.
        $demographics = array_intersect_key($data['demographics'] ?? [], array_flip($validDemoKeys));
        $totalBeneficiaries = array_sum($demographics);
        unset($data['demographics']);

        $data['wsp_plan_id']          = $wspPlan->id;
        $data['planned_beneficiaries'] = $totalBeneficiaries;
        $data['total_planned_cost']    = $totalBeneficiaries * $data['planned_cost_per_person'];

        $item = LmsWspItem::create($data);

        // Persist per-demographic counts (upsert to be safe against duplicates).
        foreach ($demographics as $key => $count) {
            LmsWspItemDemographic::updateOrCreate(
                ['wsp_item_id' => $item->id, 'demographic_key' => $key],
                ['beneficiary_count' => $count]
            );
        }

        $wspPlan->recalculateTotals();

        return back()->with('success', 'WSP item added.');
    }

    public function destroyItem(LmsWspItem $item)
    {
        $plan = $item->wspPlan;
        $item->delete();
        $plan->recalculateTotals();

        return back()->with('success', 'Item removed.');
    }

    public function suggestItems(LmsWspPlan $wspPlan)
    {
        $suggestions = $this->service->suggestWspItems($wspPlan);
        return response()->json($suggestions);
    }

    // ── ATR ────────────────────────────────────────────────────────────────────

    public function storeAtr(Request $request, LmsWspPlan $wspPlan)
    {
        $data = $request->validate([
            'seta_id'      => 'nullable|exists:lms_setas,id',
            'sdf_id'       => 'nullable|exists:lms_sdfs,id',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after:period_start',
            'notes'        => 'nullable|string',
        ]);

        $data['wsp_plan_id']  = $wspPlan->id;
        $data['report_year']  = $wspPlan->plan_year;
        $data['created_by']   = auth()->id();
        $data['seta_id']    ??= $wspPlan->seta_id;
        $data['sdf_id']     ??= $wspPlan->sdf_id;

        $atr   = LmsAtrReport::create($data);
        $count = $this->service->generateAtrItems($atr);

        return back()->with('success', "ATR created with {$count} training records.");
    }

    public function updateAtr(Request $request, LmsAtrReport $atrReport)
    {
        $data = $request->validate([
            'submission_date'     => 'nullable|date',
            'status'              => 'nullable|in:draft,submitted,approved,rejected',
            'submission_reference'=> 'nullable|string|max:80',
            'notes'               => 'nullable|string',
        ]);

        $atrReport->update($data);

        return back()->with('success', 'ATR updated.');
    }

    // ── Exports ────────────────────────────────────────────────────────────────

    public function exportWsp(LmsWspPlan $wspPlan)
    {
        $csv = $this->service->exportWspCsv($wspPlan->load('items'));

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"WSP_{$wspPlan->plan_year}.csv\"",
        ]);
    }

    public function exportAtr(LmsAtrReport $atrReport)
    {
        $csv = $this->service->exportAtrCsv($atrReport->load('items.hrPerson'));

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"ATR_{$atrReport->report_year}.csv\"",
        ]);
    }
}
