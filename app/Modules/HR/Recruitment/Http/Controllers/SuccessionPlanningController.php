<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Job;
use App\Modules\HR\Recruitment\Models\SuccessionCandidate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SuccessionPlanningController extends Controller
{
    private const READINESS_LEVELS = ['ready_now', 'ready_in_1_year', 'ready_in_2_years', 'development_needed'];

    public function index(): Response
    {
        $jobs = Job::query()
            ->with([
                'successionCandidates' => fn ($q) => $q
                    ->with(['candidate:id,first_name,last_name,email', 'nominatedBy'])
                    ->orderByRaw("CASE readiness_level
                        WHEN 'ready_now'         THEN 1
                        WHEN 'ready_in_1_year'   THEN 2
                        WHEN 'ready_in_2_years'  THEN 3
                        WHEN 'development_needed' THEN 4
                        END"),
            ])
            ->has('successionCandidates')
            ->orderBy('title')
            ->get(['id', 'title', 'location']);

        $allCandidates = Candidate::orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn ($c) => [
                'id'        => $c->id,
                'full_name' => $c->full_name,
                'email'     => $c->email,
            ]);

        $allJobs = Job::orderBy('title')
            ->get(['id', 'title', 'location'])
            ->map(fn ($j) => [
                'id'         => $j->id,
                'title'      => $j->title,
                'department' => $j->location,
            ]);

        return Inertia::render('Modules/Recruitment/Succession/Index', [
            'jobs'          => $jobs->map(fn ($job) => [
                'id'         => $job->id,
                'title'      => $job->title,
                'department' => $job->location,
                'succession_candidates' => $job->successionCandidates->map(fn ($sc) => [
                    'id'              => $sc->id,
                    'readiness_level' => $sc->readiness_level,
                    'notes'           => $sc->notes,
                    'candidate'       => [
                        'id'        => $sc->candidate->id,
                        'full_name' => $sc->candidate->full_name,
                        'email'     => $sc->candidate->email,
                    ],
                    'nominated_by' => $sc->nominatedBy ? [
                        'id'   => $sc->nominatedBy->id,
                        'name' => $sc->nominatedBy->name,
                    ] : null,
                    'created_at' => $sc->created_at?->format('d M Y'),
                ]),
            ]),
            'allCandidates' => $allCandidates,
            'allJobs'       => $allJobs,
            'readinessLevels' => self::READINESS_LEVELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'candidate_id'    => ['required', 'integer', 'exists:recruitment_candidates,id'],
            'job_id'          => ['required', 'integer', 'exists:recruitment_jobs,id'],
            'readiness_level' => ['required', Rule::in(self::READINESS_LEVELS)],
            'notes'           => ['nullable', 'string', 'max:1000'],
        ]);

        SuccessionCandidate::create([
            ...$data,
            'nominated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Succession candidate nominated.');
    }

    public function destroy(SuccessionCandidate $successionCandidate): RedirectResponse
    {
        $successionCandidate->delete();

        return back()->with('success', 'Succession candidate removed.');
    }
}
