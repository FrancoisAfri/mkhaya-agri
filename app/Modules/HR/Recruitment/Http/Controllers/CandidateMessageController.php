<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CandidateMessageController extends Controller
{
    public function store(Request $request, Job $job, Application $application): RedirectResponse
    {
        abort_unless($application->job_id === $job->id, 404);

        $validated = $request->validate([
            'channel' => ['required', 'in:email,note'],
            'subject' => ['nullable', 'string', 'max:300'],
            'body'    => ['required', 'string', 'max:10000'],
        ]);

        $application->messages()->create([
            'direction' => 'outbound',
            'channel'   => $validated['channel'],
            'subject'   => $validated['subject'] ?? null,
            'body'      => $validated['body'],
            'sent_by'   => $request->user()->id,
            'sent_at'   => now(),
        ]);

        return back()->with('success', $validated['channel'] === 'note' ? 'Note added.' : 'Message sent.');
    }
}
