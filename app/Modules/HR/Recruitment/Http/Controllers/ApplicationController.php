<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Actions\MoveApplicationAction;
use App\Modules\HR\Recruitment\Jobs\CalculateFitScore;
use Illuminate\Support\Facades\DB;
use App\Modules\HR\Recruitment\Actions\RejectApplicationAction;
use App\Modules\HR\Recruitment\Actions\WithdrawApplicationAction;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Http\Requests\MoveApplicationRequest;
use App\Modules\HR\Recruitment\Http\Resources\ApplicationResource;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function store(Request $request, Job $job): RedirectResponse
    {
        $validated = $request->validate([
            'candidate_id'      => ['required', 'integer', 'exists:recruitment_candidates,id'],
            'pipeline_stage_id' => ['nullable', 'integer', 'exists:recruitment_pipeline_stages,id'],
            'source'            => ['required', 'string'],
            'cover_letter'      => ['nullable', 'string', 'max:5000'],
        ]);

        $alreadyApplied = Application::where('candidate_id', $validated['candidate_id'])
            ->where('job_id', $job->id)
            ->whereNotIn('status', [ApplicationStatus::Rejected->value, ApplicationStatus::Withdrawn->value])
            ->exists();

        if ($alreadyApplied) {
            return back()->withErrors(['candidate_id' => 'This candidate already has an active application for this job.']);
        }

        $application = DB::transaction(function () use ($validated, $job, $request) {
            $application = new Application();
            $application->forceFill([
                'candidate_id'      => $validated['candidate_id'],
                'job_id'            => $job->id,
                'pipeline_stage_id' => $validated['pipeline_stage_id'] ?? null,
                'recruiter_id'      => $request->user()->id,
                'source'            => $validated['source'],
                'cover_letter'      => $validated['cover_letter'] ?? null,
                'applied_at'        => now(),
            ]);
            $application->status = ApplicationStatus::Applied;
            $application->save();
            return $application;
        });

        CalculateFitScore::dispatch($application)->onQueue('ai-heavy');

        return redirect()
            ->route('recruitment.jobs.applications.show', [$job->id, $application->id])
            ->with('success', 'Application created.');
    }

    public function index(Job $job, Request $request): Response
    {
        $applications = Application::with([
                'candidate:id,first_name,last_name,email',
                'candidate.aiProfile:candidate_id,summary,strengths,flags',
                'fitScore:application_id,overall_score,skills_score,experience_score,calculated_at',
                'pipelineStage:id,name,type',
                'recruiter',
            ])
            ->withCount('interviews')
            ->forJob($job->id)
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('applied_at')
            ->paginate(25)
            ->withQueryString();

        $statusOptions = collect(ApplicationStatus::cases())->map(fn($s) => [
            'value' => $s->value,
            'label' => $s->label(),
        ]);

        return Inertia::render('Modules/Recruitment/Applications/Index', [
            'job'            => ['id' => $job->id, 'title' => $job->title],
            'applications'   => ApplicationResource::collection($applications),
            'filters'        => $request->only('status'),
            'statusOptions'  => $statusOptions,
        ]);
    }

    public function show(Request $request, Job $job, Application $application): Response
    {
        $this->abortUnlessScoped($application, $job);

        $application->load([
            'candidate.skills',
            'candidate.experience',
            'candidate.qualifications',
            'candidate.aiProfile',
            'fitScore.explanations',
            'pipelineStage',
            'recruiter',
            'interviews.score',
            'job:id,title',
            'references.addedBy:id,name',
            'messages.sender:id,name',
        ]);

        $statusOptions = collect(ApplicationStatus::cases())->map(fn($s) => [
            'value' => $s->value,
            'label' => $s->label(),
        ]);

        $references = $application->references->map(fn($r) => [
            'id'             => $r->id,
            'referee_name'   => $r->referee_name,
            'referee_email'  => $r->referee_email,
            'referee_phone'  => $r->referee_phone,
            'referee_company'=> $r->referee_company,
            'referee_title'  => $r->referee_title,
            'relationship'   => $r->relationship,
            'status'         => $r->status,
            'response_notes' => $r->response_notes,
            'contacted_at'   => $r->contacted_at?->toISOString(),
            'received_at'    => $r->received_at?->toISOString(),
            'added_by'       => $r->addedBy ? ['id' => $r->addedBy->id, 'name' => $r->addedBy->name] : null,
        ]);

        $messages = $application->messages->map(fn($m) => [
            'id'        => $m->id,
            'direction' => $m->direction,
            'channel'   => $m->channel,
            'subject'   => $m->subject,
            'body'      => $m->body,
            'sent_at'   => $m->sent_at->toISOString(),
            'sender'    => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
        ]);

        return Inertia::render('Modules/Recruitment/Applications/Show', [
            'job'           => ['id' => $job->id, 'title' => $job->title],
            'application'   => (new ApplicationResource($application))->resolve(),
            'statusOptions' => $statusOptions,
            'references'    => $references,
            'messages'      => $messages,
            // The schedule-interview dialog had no interviewer field, while the request
            // object requires one — so scheduling could never succeed from this screen.
            'interviewers'  => $this->tenantPeopleOptions($request),
        ]);
    }

    public function move(
        MoveApplicationRequest $request,
        Job $job,
        Application $application,
        MoveApplicationAction $action,
    ): RedirectResponse {
        $this->abortUnlessScoped($application, $job);

        $action->execute(
            application:     $application,
            newStatus:       ApplicationStatus::from($request->validated('status')),
            userId:          $request->user()->id,
            pipelineStageId: $request->validated('pipeline_stage_id'),
            reason:          $request->validated('reason'),
        );

        return back()->with('success', 'Application moved.');
    }

    public function reject(
        Request $request,
        Job $job,
        Application $application,
        RejectApplicationAction $action,
    ): RedirectResponse {
        $this->abortUnlessScoped($application, $job);

        $request->validate(['reason' => 'required|string|max:2000']);

        $action->execute($application, $request->user()->id, $request->input('reason'));

        return back()->with('success', 'Application rejected.');
    }

    public function withdraw(
        Job $job,
        Application $application,
        WithdrawApplicationAction $action,
        Request $request,
    ): RedirectResponse {
        $this->abortUnlessScoped($application, $job);

        $action->execute($application, $request->user()->id);

        return back()->with('success', 'Application withdrawn.');
    }

    public function bulkAction(
        Request $request,
        Job $job,
        MoveApplicationAction $moveAction,
        RejectApplicationAction $rejectAction,
    ): RedirectResponse {
        abort_unless($request->user()->can('recruitment.applications.update'), 403);

        $validated = $request->validate([
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
            'action' => ['required', 'in:move,reject'],
            'status' => ['required_if:action,move', 'nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $applications = Application::whereIn('id', $validated['ids'])
            ->where('job_id', $job->id)
            ->get();

        $moved = 0;
        foreach ($applications as $application) {
            try {
                if ($validated['action'] === 'reject') {
                    $rejectAction->execute($application, $request->user()->id, $validated['reason'] ?? 'Bulk rejection');
                } else {
                    $newStatus = ApplicationStatus::from($validated['status']);
                    if ($application->status->canTransitionTo($newStatus)) {
                        $moveAction->execute(
                            application:     $application,
                            newStatus:       $newStatus,
                            userId:          $request->user()->id,
                            pipelineStageId: null,
                            reason:          $validated['reason'] ?? null,
                        );
                    }
                }
                $moved++;
            } catch (\Throwable) {
                // skip invalid transitions
            }
        }

        return back()->with('success', "{$moved} application(s) updated.");
    }

    private function abortUnlessScoped(Application $application, Job $job): void
    {
        abort_unless($application->job_id === $job->id, 404);
    }
}
