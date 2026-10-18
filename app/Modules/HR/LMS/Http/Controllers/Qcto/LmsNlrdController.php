<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\Qcto\LmsNlrdSubmission;
use App\Modules\HR\LMS\Models\Qcto\LmsSdf;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use App\Modules\HR\LMS\Services\Qcto\LmsNlrdService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LmsNlrdController extends Controller
{
    public function __construct(private readonly LmsNlrdService $service) {}

    public function index()
    {
        $submissions = LmsNlrdSubmission::with(['seta', 'sdf', 'submittedBy'])
            ->orderByDesc('created_at')
            ->get();

        $setas = LmsSeta::active()->orderBy('acronym')->get(['id','name','acronym']);
        $sdfs  = LmsSdf::active()->with('user')->get();

        return Inertia::render('Modules/LMS/Admin/NlrdSubmissions', [
            'submissions' => $submissions,
            'setas'       => $setas,
            'sdfs'        => $sdfs,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'submission_type' => 'required|in:learner_enrolment,learner_achievement',
            'period_start'    => 'required|date',
            'period_end'      => 'required|date|after:period_start',
            'seta_id'         => 'nullable|exists:lms_setas,id',
            'sdf_id'          => 'nullable|exists:lms_sdfs,id',
        ]);

        $data['submitted_by'] = auth()->id();

        $submission = LmsNlrdSubmission::create($data);

        // Validate and count records
        $errors = $this->service->validate($submission);
        $csv    = $this->service->generateCsv($submission);
        $lines  = $csv ? count(explode("\n", $csv)) - 1 : 0;

        $submission->update([
            'records_count' => $lines,
            'error_log'     => $errors ? implode("\n", $errors) : null,
            'status'        => $errors ? 'correction_required' : 'validated',
        ]);

        return back()->with('success', "Submission prepared: {$lines} records" . ($errors ? ' (with errors)' : '.'));
    }

    public function update(Request $request, LmsNlrdSubmission $submission)
    {
        $data = $request->validate([
            'submission_date'  => 'nullable|date',
            'reference_number' => 'nullable|string|max:80',
            'status'           => 'nullable|in:draft,validated,submitted,accepted,rejected,correction_required',
        ]);

        $submission->update($data);

        return back()->with('success', 'Submission updated.');
    }

    public function export(LmsNlrdSubmission $submission)
    {
        $csv = $this->service->generateCsv($submission);

        $filename = "NLRD_{$submission->submission_type}_{$submission->period_start}_{$submission->period_end}.csv";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function validate(LmsNlrdSubmission $submission)
    {
        $errors = $this->service->validate($submission);

        $submission->update([
            'error_log' => $errors ? implode("\n", $errors) : null,
            'status'    => $errors ? 'correction_required' : 'validated',
        ]);

        return back()->with(
            $errors ? 'error' : 'success',
            $errors ? 'Validation failed: ' . count($errors) . ' errors found.' : 'Validation passed.'
        );
    }

    public function resubmit(LmsNlrdSubmission $submission)
    {
        abort_unless(
            in_array($submission->status, ['correction_required', 'rejected']),
            422,
            'Only submissions in correction_required or rejected status can be resubmitted.'
        );

        $errors = $this->service->validate($submission);

        $submission->update([
            'error_log' => $errors ? implode("\n", $errors) : null,
            'status'    => $errors ? 'correction_required' : 'validated',
        ]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($submission)
            ->withProperties(['errors' => count($errors)])
            ->log('NLRD submission resubmitted for validation — ' . (count($errors) ? count($errors) . ' errors remain.' : 'passed validation.'));

        return back()->with(
            $errors ? 'error' : 'success',
            $errors ? 'Resubmission still has ' . count($errors) . ' validation errors.' : 'Submission re-validated successfully.'
        );
    }

    public function destroy(LmsNlrdSubmission $submission)
    {
        $submission->delete();
        return back()->with('success', 'Submission deleted.');
    }
}
