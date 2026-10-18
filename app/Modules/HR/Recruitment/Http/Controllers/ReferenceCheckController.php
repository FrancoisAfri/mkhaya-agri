<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\CandidateReference;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReferenceCheckController extends Controller
{
    public function store(Request $request, Job $job, Application $application): RedirectResponse
    {
        abort_unless($application->job_id === $job->id, 404);

        $validated = $request->validate([
            'referee_name'    => ['required', 'string', 'max:150'],
            'referee_email'   => ['nullable', 'email', 'max:200'],
            'referee_phone'   => ['nullable', 'string', 'max:50'],
            'referee_company' => ['nullable', 'string', 'max:150'],
            'referee_title'   => ['nullable', 'string', 'max:150'],
            'relationship'    => ['nullable', 'string', 'max:100'],
        ]);

        $application->references()->create([
            ...$validated,
            'added_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Reference added.');
    }

    public function update(Request $request, Job $job, Application $application, CandidateReference $reference): RedirectResponse
    {
        abort_unless($application->job_id === $job->id, 404);
        abort_unless($reference->application_id === $application->id, 404);

        $validated = $request->validate([
            'status'         => ['required', 'in:pending,contacted,received'],
            'response_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $updates = ['status' => $validated['status'], 'response_notes' => $validated['response_notes']];

        if ($validated['status'] === 'contacted' && !$reference->contacted_at) {
            $updates['contacted_at'] = now();
        }
        if ($validated['status'] === 'received' && !$reference->received_at) {
            $updates['received_at'] = now();
        }

        $reference->update($updates);

        return back()->with('success', 'Reference updated.');
    }

    public function destroy(Job $job, Application $application, CandidateReference $reference): RedirectResponse
    {
        abort_unless($application->job_id === $job->id, 404);
        abort_unless($reference->application_id === $application->id, 404);

        $reference->delete();

        return back()->with('success', 'Reference removed.');
    }
}
