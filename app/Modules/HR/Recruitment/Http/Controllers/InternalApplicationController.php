<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Enums\CandidateSource;
use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use App\Modules\HR\Recruitment\Events\CandidateApplied;
use App\Modules\HR\Recruitment\Jobs\CalculateFitScore;
use App\Modules\HR\Recruitment\Jobs\ParseCandidateCv;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\CandidateDocument;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InternalApplicationController extends Controller
{
    public function show(Request $request, string $vacancyUuid): Response
    {
        $vacancy = Vacancy::where('uuid', $vacancyUuid)->firstOrFail();
        $this->guardInternal($request, $vacancy);

        $user   = $request->user();
        $person = HrPerson::where('user_id', $user->id)->with('candidate')->first();
        $candidate = $person?->candidate;

        return Inertia::render('Modules/Recruitment/Internal/Apply', [
            'vacancy' => [
                'uuid'                    => $vacancy->uuid,
                'title'                   => $vacancy->title,
                'department'              => $vacancy->department,
                'job_description'         => $vacancy->job_description,
                'required_qualifications' => $vacancy->required_qualifications,
                'required_skills'         => $vacancy->required_skills ?? [],
                'salary_band_min'         => $vacancy->salary_band_min,
                'salary_band_max'         => $vacancy->salary_band_max,
                'job_type'                => $vacancy->job_type?->label(),
                'internal_deadline'       => $vacancy->internal_deadline?->toDateString(),
            ],
            'employee' => [
                'first_name'      => $candidate?->first_name,
                'last_name'       => $candidate?->last_name,
                'email'           => $candidate?->email ?? $user->email,
                'phone'           => $candidate?->phone,
                'employee_number' => $person?->employee_number,
            ],
        ]);
    }

    public function store(Request $request, string $vacancyUuid): RedirectResponse
    {
        $vacancy = Vacancy::where('uuid', $vacancyUuid)->firstOrFail();
        $this->guardInternal($request, $vacancy);

        $validated = $request->validate([
            'expected_salary' => ['nullable', 'numeric', 'min:0'],
            'location'        => ['nullable', 'string', 'max:150'],
            'cover_note'      => ['nullable', 'string', 'max:5000'],
            'cv'              => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx'],
        ]);

        $user   = $request->user();
        $person = HrPerson::where('user_id', $user->id)->with('candidate')->first();
        $cvFile = $request->file('cv');

        [$candidate, $document, $application] = DB::transaction(function () use ($user, $person, $vacancy, $cvFile) {
            $candidate = $person?->candidate ?? Candidate::firstOrCreate(
                ['email' => strtolower(trim($user->email))],
                [
                    'first_name' => $user->name ?? 'Employee',
                    'last_name'  => '',
                    'phone'      => null,
                ],
            );

            $cvPath = $cvFile->storeAs(
                "candidates/{$candidate->id}/cv",
                "cv_{$candidate->id}_{$cvFile->hashName()}",
                config('filesystems.default'),
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
                    'source'       => CandidateSource::Internal->value,
                    'applied_at'   => now(),
                ]);
                $application->status = ApplicationStatus::Applied;
                $application->save();
            }

            return [$candidate, $document, $application];
        });

        ParseCandidateCv::dispatch($candidate, $document)->onQueue('ai-heavy');
        if ($application) {
            CalculateFitScore::dispatch($application)->onQueue('ai-heavy');
            CandidateApplied::dispatch($application);
        }

        return redirect()
            ->route('recruitment.internal.job-board')
            ->with('success', 'Your internal application has been submitted.');
    }

    private function guardInternal(Request $request, Vacancy $vacancy): void
    {
        $user = $request->user();

        // Platform/master admins can always browse the internal board.
        if ($user?->is_platform_admin || $user?->is_master_admin) {
            // skip employee status check
        } else {
            // Look up the hr_persons record (preboarding module, string status)
            // NOT $user->person which points to hr_people (integer status, HR module).
            $hrPerson = HrPerson::where('user_id', $user?->id)->first();
            $status   = $hrPerson?->status instanceof \BackedEnum
                ? $hrPerson->status->value
                : $hrPerson?->status;

            abort_if(
                !in_array($status, ['active', 'onboarding'], true),
                403,
                'Internal job board is restricted to active employees.',
            );
        }

        $visibility = $vacancy->visibility instanceof \BackedEnum ? $vacancy->visibility : null;
        abort_if(
            $visibility !== VacancyVisibility::InternalOnly && $visibility !== VacancyVisibility::InternalFirstThenExternal,
            404,
            'This vacancy is not open for internal applications.',
        );

        abort_if(
            !in_array($vacancy->status, [VacancyStatus::InternalOnly, VacancyStatus::Published, VacancyStatus::External], true),
            404,
            'This vacancy is not currently accepting applications.',
        );
    }
}
