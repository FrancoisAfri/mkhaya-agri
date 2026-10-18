<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Http\Requests\StoreCandidateRequest;
use App\Modules\HR\Recruitment\Http\Requests\UploadCvRequest;
use App\Modules\HR\Recruitment\Http\Resources\CandidateDetailResource;
use App\Modules\HR\Recruitment\Http\Resources\CandidateResource;
use App\Modules\HR\Recruitment\Jobs\DetectDuplicateCandidate;
use App\Modules\HR\Recruitment\Jobs\ParseCandidateCv;
use App\Modules\HR\Recruitment\Jobs\ScreenCandidate;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\CandidateDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CandidateController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Candidate::withCount('applications')
            ->when(
                $request->filled('duplicates'),
                fn($q) => $q->whereNotNull('is_duplicate_of'),
                fn($q) => $q->whereNull('is_duplicate_of'),
            )
            ->when($request->filled('search'), fn($q) => $q->where(function ($s) use ($request) {
                $s->where('first_name', 'ilike', "%{$request->search}%")
                  ->orWhere('last_name', 'ilike', "%{$request->search}%")
                  ->orWhere('email', 'ilike', "%{$request->search}%");
            }));

        if ($request->filled('cursor')) {
            $query->where('id', '>', (int) $request->input('cursor'));
        }

        $items = $query->orderBy('id')->limit(25)->get();

        return Inertia::render('Modules/Recruitment/Candidates/Index', [
            'candidates' => [
                'data'        => $items->map(fn($c) => (new CandidateResource($c))->toArray($request))->values(),
                'next_cursor' => $items->last()?->id,
                'has_more'    => $items->count() === 25,
            ],
            'filters' => $request->only('search', 'duplicates', 'cursor'),
        ]);
    }

    public function store(StoreCandidateRequest $request): RedirectResponse
    {
        $candidate = Candidate::create($request->validated());

        DetectDuplicateCandidate::dispatch($candidate)->onQueue('default');

        return redirect()->route('recruitment.candidates.show', $candidate)
            ->with('success', 'Candidate registered.');
    }

    public function show(Candidate $candidate): Response
    {
        $candidate->load(['aiProfile', 'skills', 'experience', 'qualifications', 'documents'])->loadCount('applications');

        $applications = $candidate->applications()
            ->with(['job:id,title', 'vacancy:id,title'])
            ->get()
            ->map(fn($app) => [
                'id'         => $app->id,
                'job'        => $app->job
                    ? ['id' => $app->job->id, 'title' => $app->job->title]
                    : ($app->vacancy ? ['id' => $app->vacancy->id, 'title' => $app->vacancy->title] : null),
                'status'     => ['value' => $app->status->value, 'label' => $app->status->label()],
                'created_at' => $app->created_at->toIso8601String(),
            ]);

        return Inertia::render('Modules/Recruitment/Candidates/Show', [
            'candidate'    => (new CandidateDetailResource($candidate))->resolve(),
            'applications' => $applications,
        ]);
    }

    public function reparse(Candidate $candidate, Request $request): RedirectResponse
    {
        $document = $candidate->documents()->where('type', 'cv')->whereNotNull('path')->latest()->first();
        if (!$document) {
            return back()->withErrors(['cv' => 'No CV document found.']);
        }
        if (!$document->isVirusClean()) {
            return back()->withErrors(['cv' => 'CV has not passed virus scan yet.']);
        }
        if (!$candidate->hasAiConsent()) {
            return back()->withErrors(['consent' => 'AI consent is required before parsing.']);
        }
        ParseCandidateCv::dispatch($candidate, $document)->onQueue('ai-heavy');
        return back()->with('success', 'CV re-parsing queued.');
    }

    public function uploadCv(UploadCvRequest $request, Candidate $candidate): RedirectResponse
    {
        $file = $request->file('cv');

        $path = $file->storeAs(
            "candidates/{$candidate->id}/cv",
            "cv_{$candidate->id}_{$file->hashName()}",
            config('filesystems.default'),
        );

        $document = (new CandidateDocument())->forceFill([
            'candidate_id'  => $candidate->id,
            'type'          => 'cv',
            'original_name' => $file->getClientOriginalName(),
            'file_size'     => $file->getSize(),
            'path'          => $path,
            'mime_type'     => $file->getMimeType(),
        ]);
        $document->save();

        $candidate->cv_path      = $path;
        $candidate->cv_mime_type = $file->getMimeType();
        $candidate->save();

        ParseCandidateCv::dispatch($candidate, $document)->onQueue('ai-heavy');
        // AI Candidate Screening — consent-gated inside the job.
        ScreenCandidate::dispatch($candidate)->onQueue('ai-heavy');

        return back()->with('success', 'CV uploaded. Processing queued.');
    }

    public function consent(Candidate $candidate, Request $request): RedirectResponse
    {
        $candidate->ai_consent_at = now();
        $candidate->ai_consent_ip = $request->ip();
        $candidate->save();

        return back()->with('success', 'AI processing consent recorded.');
    }
}
