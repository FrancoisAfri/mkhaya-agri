<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Http\Requests\CreateJobRequest;
use App\Modules\HR\Recruitment\Http\Requests\UpdateJobRequest;
use App\Modules\HR\Recruitment\Http\Resources\ApplicationResource;
use App\Modules\HR\Recruitment\Http\Resources\JobResource;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use App\Modules\HR\Recruitment\Models\Shortlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Job::withCount('applications')
            ->when($request->filled('search'), fn($q) => $q->where('title', 'ilike', "%{$request->search}%"))
            ->when($request->filled('status'), function ($q) use ($request) {
                return match ($request->status) {
                    'active' => $q->where('is_active', true)
                        ->where(fn($s) => $s->whereNull('closes_at')->orWhere('closes_at', '>', now())),
                    'draft'  => $q->where('is_active', false)->whereNull('posted_at'),
                    'closed' => $q->where('closes_at', '<', now()),
                    'filled' => $q->where('is_active', false)->whereNotNull('posted_at'),
                    default  => $q,
                };
            })
            ->latest('posted_at');

        $jobs = $query->paginate(25)->withQueryString();

        return Inertia::render('Modules/Recruitment/Jobs/Index', [
            'jobs'    => JobResource::collection($jobs),
            'filters' => $request->only('search', 'status'),
            'stats'   => [
                'total'  => Job::count(),
                'active' => Job::where('is_active', true)
                    ->where(fn($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
                    ->count(),
                'filled' => Job::where('is_active', false)->whereNotNull('posted_at')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Recruitment/Jobs/Create');
    }

    public function store(CreateJobRequest $request): RedirectResponse
    {
        $job = Job::create(array_merge(
            $request->validated(),
            ['created_by' => $request->user()->id],
        ));

        return redirect()->route('recruitment.jobs.show', $job)
            ->with('success', 'Job posting created.');
    }

    public function show(Job $job): Response
    {
        $job->load([
            'pipelineStages',
            'createdBy.person:id,user_id,first_name,surname',
        ])->loadCount('applications');

        $applications = Application::with([
                'candidate:id,first_name,last_name,email',
                'pipelineStage:id,name,type',
            ])
            ->forJob($job->id)
            ->orderByDesc('applied_at')
            ->get();

        $stats = [
            'total_applications' => $job->applications_count,
            'shortlisted'        => Application::forJob($job->id)
                ->where('status', ApplicationStatus::Shortlisted->value)->count(),
            'interviewed'        => Application::forJob($job->id)
                ->where('status', ApplicationStatus::Interview->value)->count(),
            'offered'            => Application::forJob($job->id)
                ->where('status', ApplicationStatus::OfferSent->value)->count(),
        ];

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
                'approved_at'  => $s->approved_at?->toIso8601String(),
                'created_at'   => $s->created_at->toIso8601String(),
            ]);

        return Inertia::render('Modules/Recruitment/Jobs/Show', [
            'job'          => (new JobResource($job))->resolve(),
            'applications' => ApplicationResource::collection($applications),
            'requisitions' => [],
            'shortlists'   => $shortlists,
            'stats'        => $stats,
        ]);
    }

    public function update(UpdateJobRequest $request, Job $job): RedirectResponse
    {
        $job->update(array_merge(
            $request->validated(),
            ['updated_by' => $request->user()->id],
        ));

        return back()->with('success', 'Job posting updated.');
    }

    public function destroy(Job $job): RedirectResponse
    {
        $this->authorize('delete', $job);

        $job->delete();

        return redirect()->route('recruitment.jobs.index')->with('success', 'Job posting deleted.');
    }
}
