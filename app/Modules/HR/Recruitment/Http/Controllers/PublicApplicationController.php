<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Enums\CandidateSource;
use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Events\CandidateApplied;
use App\Modules\HR\Recruitment\Jobs\CalculateFitScore;
use App\Modules\HR\Recruitment\Jobs\DetectDuplicateCandidate;
use App\Modules\HR\Recruitment\Jobs\ParseCandidateCv;
use App\Modules\HR\Recruitment\Jobs\ScreenCandidate;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\CandidateDocument;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PublicApplicationController
{
    /**
     * Show the public application form for external candidates.
     * No authentication required.
     */
    public function show(string $token): Response
    {
        $vacancy = Vacancy::where('application_token', $token)->first();

        $externalStatuses = [VacancyStatus::External, VacancyStatus::Published];
        if (!$vacancy || !in_array($vacancy->status, $externalStatuses)) {
            abort(404, 'This vacancy is not available for external applications.');
        }

        return Inertia::render('Modules/Recruitment/Public/Apply', [
            'vacancy' => [
                'vacancy_number'          => $vacancy->vacancy_number,
                'title'                   => $vacancy->title,
                'department'              => $vacancy->department,
                'job_description'         => $vacancy->job_description,
                'required_qualifications' => $vacancy->required_qualifications,
                'required_skills'         => $vacancy->required_skills ?? [],
                'salary_band_min'         => $vacancy->salary_band_min,
                'salary_band_max'         => $vacancy->salary_band_max,
                'job_type'                => $vacancy->job_type?->label(),
                'expires_at'              => $vacancy->expires_at?->toDateString(),
            ],
            'token' => $token,
        ]);
    }

    /**
     * Handle external candidate application submission.
     * No authentication required.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $vacancy = Vacancy::where('application_token', $token)->first();
        $externalStatuses = [VacancyStatus::External, VacancyStatus::Published];
        if (!$vacancy || !in_array($vacancy->status, $externalStatuses)) {
            abort(404, 'This vacancy is not available for external applications.');
        }

        $validated = $request->validate([
            'first_name'      => ['required', 'string', 'max:100'],
            'last_name'       => ['required', 'string', 'max:100'],
            'email'           => ['required', 'email', 'max:254'],
            'phone'           => ['required', 'string', 'max:30'],
            'expected_salary' => ['nullable', 'numeric', 'min:0'],
            'location'        => ['nullable', 'string', 'max:150'],
            'cv'              => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx'],
            'ai_consent'      => ['nullable', 'boolean'],
        ]);

        $cvFile = $request->file('cv');

        // Guests have no tenant context (ResolveCompany skips them), so derive it
        // from the vacancy: RLS then scopes the candidate lookup to the vacancy's
        // company and every insert is tagged to it — instead of company_id = NULL
        // rows that the hiring company can never see. Dispatching inside the
        // context also propagates the tenant to the queued jobs.
        return app(\App\Modules\Tenancy\Services\TenantContext::class)->run(
            $vacancy->company_id !== null ? (int) $vacancy->company_id : null,
            function () use ($validated, $cvFile, $vacancy, $request, $token) {

        [$candidate, $document, $application] = DB::transaction(function () use ($validated, $cvFile, $vacancy, $request) {
            // Find or create candidate (keyed by email)
            $candidate = Candidate::firstOrCreate(
                ['email' => strtolower(trim($validated['email']))],
                [
                    'first_name' => $validated['first_name'],
                    'last_name'  => $validated['last_name'],
                    'phone'      => $validated['phone'],
                ]
            );

            // Record AI consent if given
            if (!empty($validated['ai_consent'])) {
                $candidate->ai_consent_at = now();
                $candidate->ai_consent_ip = $request->ip();
                $candidate->save();
            }

            // Store CV — private disk, never publicly accessible
            $cvPath = $cvFile->storeAs(
                "candidates/{$candidate->id}/cv",
                "cv_{$candidate->id}_{$cvFile->hashName()}",
                config('filesystems.default')
            );

            $document = (new CandidateDocument())->forceFill([
                'candidate_id'  => $candidate->id,
                'type'          => 'cv',
                'original_name' => $cvFile->getClientOriginalName(),
                'file_size'     => $cvFile->getSize(),
                'path'          => $cvPath,
                'mime_type'     => $cvFile->getMimeType(),
            ]);
            $document->save();

            $candidate->cv_path      = $cvPath;
            $candidate->cv_mime_type = $cvFile->getMimeType();
            $candidate->save();

            // Check if candidate already applied to this vacancy
            $alreadyApplied = Application::where('candidate_id', $candidate->id)
                ->where('vacancy_id', $vacancy->id)
                ->whereNotIn('status', [ApplicationStatus::Rejected->value, ApplicationStatus::Withdrawn->value])
                ->exists();

            $application = null;
            if (!$alreadyApplied) {
                $application = new Application();
                $application->forceFill([
                    'candidate_id' => $candidate->id,
                    'vacancy_id'   => $vacancy->id,
                    'source'       => CandidateSource::External->value,
                    'applied_at'   => now(),
                ]);
                $application->status = ApplicationStatus::Applied;
                $application->save();
            }

            return [$candidate, $document, $application];
        });

        // Dispatch background jobs after transaction commits (still inside the
        // tenant context so the queue payload carries the vacancy's company).
        DetectDuplicateCandidate::dispatch($candidate)->onQueue('default');
        ParseCandidateCv::dispatch($candidate, $document)->onQueue('ai-heavy');
        if ($application) {
            CalculateFitScore::dispatch($application)->onQueue('ai-heavy');
            // AI Candidate Screening — analyse public professional presence (consent-gated
            // inside the job; records a skipped_no_consent audit row if consent is absent).
            ScreenCandidate::dispatch($candidate, $application)->onQueue('ai-heavy');
            CandidateApplied::dispatch($application);
        }

        return redirect()
            ->route('recruitment.public.success', $token)
            ->with('success', 'Your application has been received.');
            }
        );
    }

    /**
     * Show the application success page.
     * No authentication required.
     */
    public function success(string $token): Response
    {
        $vacancy = Vacancy::where('application_token', $token)->first();

        return Inertia::render('Modules/Recruitment/Public/ApplicationSuccess', [
            'jobBoardUrl' => route('recruitment.jobs.index'),
        ]);
    }
}
