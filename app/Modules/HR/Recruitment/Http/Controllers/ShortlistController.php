<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Actions\ApproveOverrideAction;
use App\Modules\HR\Recruitment\Actions\ApproveShortlistAction;
use App\Modules\HR\Recruitment\Actions\OverrideShortlistAction;
use App\Modules\HR\Recruitment\Actions\RejectShortlistAction;
use App\Modules\HR\Recruitment\Enums\ShortlistStatus;
use App\Modules\HR\Recruitment\Events\ShortlistGenerated;
use App\Modules\HR\Recruitment\Http\Requests\OverrideShortlistRequest;
use App\Modules\HR\Recruitment\Http\Resources\ShortlistResource;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\FitScore;
use App\Modules\HR\Recruitment\Models\Job;
use App\Modules\HR\Recruitment\Models\Shortlist;
use App\Modules\HR\Recruitment\Models\ShortlistItem;
use App\Modules\HR\Recruitment\Models\ShortlistOverride;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ShortlistController extends Controller
{
       use AuthorizesRequests;
    public function index(Job $job): Response
    {
        $shortlists = Shortlist::where('job_id', $job->id)
            ->with(['generatedBy', 'approvedBy'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($s) => [
                'id'           => $s->id,
                'status'       => $s->status->value,
                'status_label' => $s->status->label(),
                'items_count'  => $s->items_count,
                'generated_by' => $s->generatedBy ? ['id' => $s->generatedBy->id, 'name' => $s->generatedBy->name] : null,
                'approved_by'  => $s->approvedBy  ? ['id' => $s->approvedBy->id,  'name' => $s->approvedBy->name]  : null,
                'approved_at'  => $s->approved_at?->toIso8601String(),
                'created_at'   => $s->created_at->toIso8601String(),
            ]);

        return Inertia::render('Modules/Recruitment/Shortlists/Index', [
            'job'        => ['id' => $job->id, 'title' => $job->title],
            'shortlists' => $shortlists,
        ]);
    }

    public function submit(Job $job, Shortlist $shortlist): RedirectResponse
    {
        $this->abortUnlessScoped($shortlist, $job);

        if ($shortlist->status !== ShortlistStatus::Draft) {
            return back()->with('error', 'Only draft shortlists can be submitted for approval.');
        }

        $shortlist->update(['status' => ShortlistStatus::Pending->value]);

        return back()->with('success', 'Shortlist submitted for approval.');
    }

    public function show(Job $job, Shortlist $shortlist): Response
    {
        $this->abortUnlessScoped($shortlist, $job);

        $shortlist->load([
            'generatedBy',
            'approvedBy',
            'items' => fn($q) => $q->with([
                'candidate:id,uuid,first_name,last_name,email',
                'candidate.aiProfile:candidate_id,summary,strengths,flags',
                'application.fitScore:application_id,overall_score',
            ])->orderBy('rank'),
            'overrides.overriddenBy',
            'overrides.approver',
        ]);

        return Inertia::render('Modules/Recruitment/Shortlists/Show', [
            'job'       => ['id' => $job->id, 'title' => $job->title],
            'shortlist' => (new ShortlistResource($shortlist))->resolve(),
        ]);
    }

    public function generate(Job $job, Request $request): RedirectResponse
    {
        $this->authorize('create', Shortlist::class);

        $topApplications = FitScore::with('application:id,candidate_id,job_id')
            ->where('job_id', $job->id)
            ->whereNotNull('overall_score')
            ->orderByDesc('overall_score')
            ->limit(20)
            ->get();

        if ($topApplications->isEmpty()) {
            return back()->with('error', 'No scored applications to shortlist yet.');
        }

        $shortlist = DB::transaction(function () use ($job, $topApplications, $request) {
            $shortlist = Shortlist::create([
                'job_id'       => $job->id,
                'generated_by' => $request->user()->id,
                'status'       => ShortlistStatus::Draft->value,
            ]);

            $items = $topApplications->values()->map(fn($score, $index) => [
                'shortlist_id'   => $shortlist->id,
                'candidate_id'   => $score->application->candidate_id,
                'application_id' => $score->application->id,
                'rank'           => $index + 1,
                'ai_recommended' => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ])->toArray();

            ShortlistItem::insert($items);

            return $shortlist;
        });

        ShortlistGenerated::dispatch($shortlist);

        return redirect()->route('recruitment.jobs.shortlists.show', [$job->id, $shortlist->id])
            ->with('success', 'Shortlist generated.');
    }

    public function approve(
        Job $job,
        Shortlist $shortlist,
        ApproveShortlistAction $action,
        Request $request,
    ): RedirectResponse {
        $this->abortUnlessScoped($shortlist, $job);

        $action->execute($shortlist, $request->user()->id);

        return back()->with('success', 'Shortlist approved.');
    }

    public function reject(
        Request $request,
        Job $job,
        Shortlist $shortlist,
        RejectShortlistAction $action,
    ): RedirectResponse {
        $this->abortUnlessScoped($shortlist, $job);

        $request->validate(['reason' => 'required|string|max:2000']);

        $action->execute($shortlist, $request->user()->id, $request->input('reason'));

        return back()->with('success', 'Shortlist rejected.');
    }

    public function override(
        OverrideShortlistRequest $request,
        Job $job,
        Shortlist $shortlist,
        OverrideShortlistAction $action,
    ): RedirectResponse {
        $this->abortUnlessScoped($shortlist, $job);

        $candidate = Candidate::findOrFail($request->validated('candidate_id'));

        $action->execute(
            shortlist:      $shortlist,
            candidate:      $candidate,
            overrideAction: $request->validated('action'),
            reason:         $request->validated('override_reason'),
            requesterId:    $request->user()->id,
        );

        return back()->with('success', 'Override requested. Awaiting second approval.');
    }

    public function approveOverride(
        Job $job,
        Shortlist $shortlist,
        ShortlistOverride $override,
        ApproveOverrideAction $action,
        Request $request,
    ): RedirectResponse {
        $this->abortUnlessScoped($shortlist, $job);
        abort_unless($override->shortlist_id === $shortlist->id, 404);

        $action->execute($override, $request->user()->id);

        return back()->with('success', 'Override approved and applied.');
    }

    private function abortUnlessScoped(Shortlist $shortlist, Job $job): void
    {
        abort_unless($shortlist->job_id === $job->id, 404);
    }
}
