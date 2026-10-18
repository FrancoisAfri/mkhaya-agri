<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnership;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipLogbook;
use App\Modules\HR\LMS\Models\Qcto\LmsBbeeTrainingSpend;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use App\Modules\HR\LMS\Services\Qcto\LmsBbeeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LmsLearnershipController extends Controller
{
    use FileStorage;

    // ── Learnerships CRUD ──────────────────────────────────────────────────────

    public function index()
    {
        $learnerships = LmsLearnership::with(['seta', 'knowledgeCourse', 'practicalCourse', 'aqp'])
            ->withCount('enrolments')
            ->orderByDesc('created_at')
            ->get();

        $setas   = LmsSeta::active()->orderBy('acronym')->get(['id', 'name', 'acronym']);
        $courses = LmsCourse::orderBy('title')
            ->get(['id', 'title', 'nqf_level', 'credits', 'is_published']);

        $aqps = \App\Modules\HR\LMS\Models\Qcto\LmsAqp::orderBy('name')
            ->get(['id', 'name', 'acronym']);

        return Inertia::render('Modules/LMS/Admin/Learnerships', [
            'learnerships' => $learnerships,
            'setas'        => $setas,
            'courses'      => $courses,
            'aqps'         => $aqps,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'               => 'required|string|max:200',
            'saqa_id'             => 'nullable|string|max:20',
            'seta_id'             => 'nullable|exists:lms_setas,id',
            'aqp_id'              => 'nullable|exists:lms_aqps,id',
            'nqf_level'           => 'nullable|integer|min:1|max:10',
            'total_credits'       => 'nullable|numeric|min:0',
            'duration_months'     => 'nullable|integer|min:1',
            'learnership_type'    => 'required|in:learnership,apprenticeship,skills_programme,internship',
            'ofo_code'            => ['nullable', 'string', 'max:7', 'regex:/^\d{7}$/'],
            'seta_programme_code' => 'nullable|string|max:60',
            'k_course_id'         => 'nullable|exists:lms_courses,id',
            'p_course_id'         => 'nullable|exists:lms_courses,id',
            'we_hours_required'   => 'nullable|numeric|min:0',
            'we_credits'          => 'nullable|numeric|min:0',
            'stipend_per_month'   => 'nullable|numeric|min:0',
            'description'         => 'nullable|string',
            'is_active'           => 'boolean',
        ]);

        $data['created_by'] = auth()->id();
        $learnership = LmsLearnership::create($data);

        $this->warnCreditMismatch($learnership);

        return back()->with('success', 'Learnership saved.');
    }

    public function update(Request $request, LmsLearnership $learnership)
    {
        $data = $request->validate([
            'title'               => 'required|string|max:200',
            'saqa_id'             => 'nullable|string|max:20',
            'seta_id'             => 'nullable|exists:lms_setas,id',
            'aqp_id'              => 'nullable|exists:lms_aqps,id',
            'nqf_level'           => 'nullable|integer|min:1|max:10',
            'total_credits'       => 'nullable|numeric|min:0',
            'duration_months'     => 'nullable|integer|min:1',
            'learnership_type'    => 'required|in:learnership,apprenticeship,skills_programme,internship',
            'ofo_code'            => ['nullable', 'string', 'max:7', 'regex:/^\d{7}$/'],
            'seta_programme_code' => 'nullable|string|max:60',
            'k_course_id'         => 'nullable|exists:lms_courses,id',
            'p_course_id'         => 'nullable|exists:lms_courses,id',
            'we_hours_required'   => 'nullable|numeric|min:0',
            'we_credits'          => 'nullable|numeric|min:0',
            'stipend_per_month'   => 'nullable|numeric|min:0',
            'description'         => 'nullable|string',
            'is_active'           => 'boolean',
        ]);

        $learnership->update($data);
        $learnership->refresh()->load(['knowledgeCourse', 'practicalCourse']);

        $this->warnCreditMismatch($learnership);

        return back()->with('success', 'Learnership updated.');
    }

    public function destroy(LmsLearnership $learnership)
    {
        $learnership->delete();
        return back()->with('success', 'Learnership deleted.');
    }

    // ── Enrolments ─────────────────────────────────────────────────────────────

    public function enrolments(LmsLearnership $learnership)
    {
        $enrolments = $learnership->enrolments()
            ->with('hrPerson')
            ->orderByDesc('start_date')
            ->get();

        $employees = HRPerson::orderBy('first_name')->get(['id', 'first_name', 'surname', 'employee_number']);

        return Inertia::render('Modules/LMS/Admin/LearnershipEnrolments', [
            'learnership' => $learnership->load('seta'),
            'enrolments'  => $enrolments,
            'employees'   => $employees,
        ]);
    }

    public function storeEnrolment(Request $request, LmsLearnership $learnership)
    {
        $data = $request->validate([
            'hr_person_id'            => 'required|exists:hr_people,id',
            'seta_learner_id'         => 'nullable|string|max:60',
            'seta_registration_number'=> 'nullable|string|max:60',
            'start_date'              => 'required|date',
            'end_date'                => 'nullable|date|after_or_equal:start_date',
            'stipend_amount'          => 'nullable|numeric|min:0',
            'notes'                   => 'nullable|string',
        ]);

        // QCTO requires an AQP to administer EISA; without one the learner
        // can never sit the summative assessment and the qualification is uncompletable.
        if (!$learnership->aqp_id) {
            return back()->withErrors([
                'hr_person_id' => "Learnership \"{$learnership->title}\" has no Assessment Quality Partner (AQP) assigned. QCTO requires an AQP before learners can be enrolled — the EISA cannot be scheduled without one. Assign an AQP on the Learnerships page first.",
            ]);
        }

        $data['learnership_id'] = $learnership->id;
        $data['status']         = 'registered';
        $data['created_by']     = auth()->id();

        LmsLearnershipEnrolment::create($data);

        return back()->with('success', 'Learner enrolled.');
    }

    public function updateEnrolment(Request $request, LmsLearnershipEnrolment $enrolment)
    {
        $previousStatus = $enrolment->status;

        $data = $request->validate([
            'seta_learner_id'         => 'nullable|string|max:60',
            'seta_registration_number'=> 'nullable|string|max:60',
            'start_date'              => 'required|date',
            'end_date'                => 'nullable|date',
            'status'                  => 'required|in:registered,active,completed,withdrawn,transferred,lapsed',
            'final_assessment_date'   => 'nullable|date',
            'final_score'             => 'nullable|numeric|min:0|max:100',
            'passed'                  => 'nullable|boolean',
            'certificate_number'      => 'nullable|string|max:60',
            'certificate_issued_at'   => 'nullable|date',
            'stipend_amount'          => 'nullable|numeric|min:0',
            'notes'                   => 'nullable|string',
        ]);

        $enrolment->update($data);

        if ($previousStatus !== 'completed' && ($data['status'] ?? '') === 'completed') {
            app(LmsBbeeService::class)->recordLearnershipSpend($enrolment->refresh());
        }

        return back()->with('success', 'Enrolment updated.');
    }

    // ── WBL Logbook ────────────────────────────────────────────────────────────

    public function logbook(LmsLearnershipEnrolment $enrolment)
    {
        return response()->json(
            $enrolment->logbookEntries()->orderBy('log_date')->get()
        );
    }

    public function addLogbookEntry(Request $request, LmsLearnershipEnrolment $enrolment)
    {
        $request->validate([
            'log_date'             => 'required|date',
            'activity_description' => 'required|string',
            'hours_logged'         => 'required|numeric|min:0.5|max:24',
            'workplace_coach'      => 'nullable|string|max:120',
            'learner_reflection'   => 'nullable|string',
            'evidence_file'        => 'nullable|file|max:10240',
        ]);

        $fileData  = null;
        $fileName  = null;
        $mimeType  = null;
        $sizeBytes = null;

        if ($request->hasFile('evidence_file')) {
            try {
                $stored    = $this->saveFileToDatabase($request->file('evidence_file'));
                $fileData  = $stored['data'];
                $fileName  = $stored['original_name'];
                $mimeType  = $stored['mime'];
                $sizeBytes = $stored['size'];
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['evidence_file' => $e->getMessage()]);
            }
        }

        $entry = new LmsLearnershipLogbook([
            'learnership_enrolment_id' => $enrolment->id,
            'log_date'                 => $request->log_date,
            'activity_description'     => $request->activity_description,
            'hours_logged'             => $request->hours_logged,
            'workplace_coach'          => $request->workplace_coach,
            'learner_reflection'       => $request->learner_reflection,
            'evidence_file_name'       => $fileName,
            'evidence_mime_type'       => $mimeType,
            'evidence_size_bytes'      => $sizeBytes,
        ]);

        // A logbook entry is a written record first; the evidence file is optional.
        if ($fileData !== null) {
            $entry->writeBytes(base64_decode($fileData), pathinfo((string) $fileName, PATHINFO_EXTENSION));
        }

        $entry->save();

        $totalHours = $enrolment->logbookEntries()->sum('hours_logged');
        $weRequired = $enrolment->learnership->we_hours_required;

        $enrolment->update([
            'we_hours_logged'     => $totalHours,
            'we_logbook_complete' => $weRequired > 0 && $totalHours >= $weRequired,
        ]);

        return response()->json($entry);
    }

    public function downloadLogbookEvidence(LmsLearnershipLogbook $entry)
    {
        abort_unless($entry->hasEvidence(), 404);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($entry)
            ->log('Logbook evidence "' . $entry->evidence_file_name . '" downloaded.');

        return $entry->fileResponse($entry->evidence_file_name, $entry->evidence_mime_type);
    }

    public function signOffLogbook(Request $request, LmsLearnershipLogbook $entry)
    {
        $request->validate([
            'workplace_coach' => 'required|string|max:120',
        ]);

        $entry->update([
            'coach_signed_off' => true,
            'signed_off_at'    => now(),
            'workplace_coach'  => $request->workplace_coach,
        ]);

        return back()->with('success', 'Logbook entry signed off.');
    }

    // ── Absorption (B-BBEE sub-indicator 5) ───────────────────────────────────

    public function markAbsorbed(LmsLearnershipEnrolment $enrolment)
    {
        abort_unless($enrolment->status === 'completed', 422, 'Only completed enrolments can be marked as absorbed.');

        $year = $enrolment->end_date?->year ?? now()->year;

        LmsBbeeTrainingSpend::where('hr_person_id', $enrolment->hr_person_id)
            ->where('spend_year', $year)
            ->where('is_learnership', true)
            ->update(['is_absorbed' => true]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($enrolment)
            ->log('Learner marked as absorbed post-learnership for enrolment #' . $enrolment->id . '.');

        return back()->with('success', 'Learner marked as absorbed.');
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function warnCreditMismatch(LmsLearnership $learnership): void
    {
        if ($learnership->hasCreditMismatch()) {
            session()->flash(
                'warning',
                "Credit mismatch on \"{$learnership->title}\": K+P component credits (" .
                $learnership->getTotalComponentCredits() .
                ') do not equal total_credits (' . $learnership->total_credits .
                '). Correct before WSP/ATR or NLRD submission.'
            );
        }
    }
}
