<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\Qcto\LmsModerationReport;
use App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LmsModerationController extends Controller
{
    public function index()
    {
        $reports = LmsModerationReport::with(['course', 'moderator', 'assessor'])
            ->orderByDesc('moderation_date')
            ->get();

        $courses    = LmsCourse::orderBy('title')->get(['id','title']);
        $moderators = LmsRegisteredPerson::moderators()->active()->get(['id','first_name','last_name']);
        $assessors  = LmsRegisteredPerson::assessors()->active()->get(['id','first_name','last_name']);

        return Inertia::render('Modules/LMS/Admin/ModerationReports', [
            'reports'    => $reports,
            'courses'    => $courses,
            'moderators' => $moderators,
            'assessors'  => $assessors,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'course_id'        => 'nullable|exists:lms_courses,id',
            'moderation_type'  => 'required|in:internal,external',
            'moderator_id'     => 'required|exists:lms_registered_persons,id',
            'assessor_id'      => 'nullable|exists:lms_registered_persons,id',
            'moderation_date'  => 'required|date',
            'total_assessed'   => 'required|integer|min:0',
            'sampled_count'    => 'required|integer|min:0',
            'agreed_count'     => 'required|integer|min:0',
            'findings'         => 'nullable|string',
            'recommendations'  => 'nullable|string',
            'action_required'  => 'boolean',
            'corrective_actions'=> 'nullable|string',
        ]);

        // QCTO/SAQA Moderation Policy: minimum 20% sampling rate required.
        $requiredSample = (int) ceil($data['total_assessed'] * 0.20);
        if ($data['total_assessed'] > 0 && $data['sampled_count'] < $requiredSample) {
            return back()->withErrors([
                'sampled_count' => "Sampled count ({$data['sampled_count']}) is below the required 20% minimum of {$data['total_assessed']} assessed learners ({$requiredSample} required). QCTO moderation policy requires at least 20% sampling.",
            ]);
        }

        $data['disagreed_count'] = max(0, $data['sampled_count'] - $data['agreed_count']);
        $data['sample_pct']      = $data['total_assessed'] > 0
            ? round(($data['sampled_count'] / $data['total_assessed']) * 100, 2)
            : 0;
        $data['agreement_rate']  = $data['sampled_count'] > 0
            ? round(($data['agreed_count'] / $data['sampled_count']) * 100, 2)
            : 0;
        $data['created_by']      = auth()->id();

        LmsModerationReport::create($data);

        return back()->with('success', 'Moderation report saved.');
    }

    public function update(Request $request, LmsModerationReport $report)
    {
        $data = $request->validate([
            'moderation_type'          => 'required|in:internal,external',
            'moderation_date'          => 'required|date',
            'total_assessed'           => 'required|integer|min:0',
            'sampled_count'            => 'required|integer|min:0',
            'agreed_count'             => 'required|integer|min:0',
            'findings'                 => 'nullable|string',
            'recommendations'          => 'nullable|string',
            'action_required'          => 'boolean',
            'corrective_actions'       => 'nullable|string',
            'status'                   => 'nullable|in:draft,submitted,approved,rejected',
            'seta_submission_reference'=> 'nullable|string|max:80',
            'seta_submitted_at'        => 'nullable|date',
        ]);

        $requiredSample = (int) ceil($data['total_assessed'] * 0.20);
        if ($data['total_assessed'] > 0 && $data['sampled_count'] < $requiredSample) {
            return back()->withErrors([
                'sampled_count' => "Sampled count ({$data['sampled_count']}) is below the required 20% minimum of {$data['total_assessed']} assessed learners ({$requiredSample} required).",
            ]);
        }

        $data['disagreed_count'] = max(0, $data['sampled_count'] - $data['agreed_count']);
        $data['sample_pct']      = $data['total_assessed'] > 0
            ? round(($data['sampled_count'] / $data['total_assessed']) * 100, 2)
            : 0;
        $data['agreement_rate']  = $data['sampled_count'] > 0
            ? round(($data['agreed_count'] / $data['sampled_count']) * 100, 2)
            : 0;

        $report->update($data);

        return back()->with('success', 'Report updated.');
    }

    public function acknowledge(Request $request, LmsModerationReport $report)
    {
        abort_unless(!is_null($report->seta_submitted_at), 422, 'Report must be submitted to SETA before it can be acknowledged.');
        abort_unless(is_null($report->seta_acknowledged_at), 422, 'Report has already been acknowledged.');

        $data = $request->validate([
            'seta_acknowledgement_reference' => 'nullable|string|max:80',
        ]);

        $report->update([
            'seta_acknowledged_at'           => now(),
            'seta_acknowledgement_reference' => $data['seta_acknowledgement_reference'] ?? null,
            'acknowledged_by'                => auth()->id(),
            'status'                         => 'approved',
        ]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($report)
            ->withProperties(['reference' => $data['seta_acknowledgement_reference'] ?? null])
            ->log('SETA acknowledgement recorded for moderation report #' . $report->id . '.');

        return back()->with('success', 'SETA acknowledgement recorded.');
    }

    public function destroy(LmsModerationReport $report)
    {
        $report->delete();
        return back()->with('success', 'Report deleted.');
    }
}
