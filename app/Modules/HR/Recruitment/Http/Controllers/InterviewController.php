<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Events\InterviewScheduled;
use App\Modules\HR\Recruitment\Http\Requests\ScheduleInterviewRequest;
use App\Modules\HR\Recruitment\Http\Requests\SubmitInterviewScoreRequest;
use App\Modules\HR\Recruitment\Http\Resources\InterviewResource;
use App\Modules\HR\Recruitment\Jobs\GenerateInterviewQuestionsJob;
use App\Modules\HR\Recruitment\Jobs\ScoreInterviewResponses;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Interview;
use App\Modules\HR\Recruitment\Models\InterviewScore;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Inertia\Inertia;
use Inertia\Response;

class InterviewController extends Controller
{
    public function store(
        ScheduleInterviewRequest $request,
        Job $job,
        Application $application,
    ): RedirectResponse {
        $interview = Interview::create([
            'application_id' => $application->id,
            'status'         => 'scheduled',
            ...$request->validated(),
        ]);

        Event::dispatch(new InterviewScheduled($interview));

        return back()->with('success', 'Interview scheduled.');
    }

    public function show(
        Job $job,
        Application $application,
        Interview $interview,
    ): Response {
        $this->abortUnlessScoped($interview, $application);

        $interview->load(['interviewer', 'questions', 'score.scoredBy', 'application.job:id,title', 'application.candidate:id,first_name,last_name']);

        return Inertia::render('Modules/Recruitment/Interviews/Show', [
            'job'         => ['id' => $job->id, 'title' => $job->title],
            'application' => ['id' => $application->id],
            'interview'   => (new InterviewResource($interview))->resolve(),
            'candidate'   => [
                'id'        => $interview->application->candidate->id,
                'full_name' => $interview->application->candidate->full_name,
            ],
        ]);
    }

    public function generateQuestions(
        Job $job,
        Application $application,
        Interview $interview,
        Request $request,
    ): RedirectResponse {
        $this->abortUnlessScoped($interview, $application);

        GenerateInterviewQuestionsJob::dispatch($interview)->onQueue('ai-fast');

        return back()->with('success', 'AI question generation queued.');
    }

    public function score(
        SubmitInterviewScoreRequest $request,
        Job $job,
        Application $application,
        Interview $interview,
    ): RedirectResponse {
        $this->abortUnlessScoped($interview, $application);

        InterviewScore::updateOrCreate(
            ['interview_id' => $interview->id],
            array_merge($request->validated(), [
                'scored_by' => $request->user()->id,
                'scored_at' => now(),
            ]),
        );

        return back()->with('success', 'Score submitted.');
    }

    public function complete(
        Job $job,
        Application $application,
        Interview $interview,
        Request $request,
    ): RedirectResponse {
        $this->abortUnlessScoped($interview, $application);

        // status is guarded on the model (never mass-assignable); force-fill it.
        $interview->forceFill(['status' => 'completed'])->save();

        ScoreInterviewResponses::dispatch($interview)->onQueue('ai-fast');

        return back()->with('success', 'Interview marked complete. AI scoring queued.');
    }

    public function cancel(
        Job $job,
        Application $application,
        Interview $interview,
    ): RedirectResponse {
        $this->abortUnlessScoped($interview, $application);

        // status is guarded on the model (never mass-assignable); force-fill it.
        $interview->forceFill(['status' => 'cancelled'])->save();

        return back()->with('success', 'Interview cancelled.');
    }

    private function abortUnlessScoped(Interview $interview, Application $application): void
    {
        abort_unless($interview->application_id === $application->id, 404);
    }
}
