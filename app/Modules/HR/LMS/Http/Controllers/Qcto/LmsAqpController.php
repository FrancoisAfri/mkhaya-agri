<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsAqp;
use App\Modules\HR\LMS\Models\Qcto\LmsEisaSchedule;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnership;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use App\Modules\HR\LMS\Services\Qcto\LmsBbeeService;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LmsAqpController extends Controller
{
    // ── AQP CRUD ───────────────────────────────────────────────────────────────

    public function index()
    {
        $aqps = LmsAqp::with('seta')
            ->withCount('learnerships', 'eisaSchedules')
            ->orderBy('name')
            ->get();

        $setas = LmsSeta::active()->orderBy('acronym')->get(['id', 'name', 'acronym']);

        return Inertia::render('Modules/LMS/Admin/AqpEisa', [
            'aqps'         => $aqps,
            'setas'        => $setas,
            'learnerships' => LmsLearnership::active()->with('aqp')->orderBy('title')->get(['id', 'title', 'aqp_id', 'saqa_id', 'nqf_level']),
            'employees'    => HRPerson::orderBy('first_name')->get(['id', 'first_name', 'surname', 'employee_number']),
            'eisaSchedules'=> LmsEisaSchedule::with(['learnership', 'aqp', 'hrPerson'])->orderByDesc('scheduled_date')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                => 'required|string|max:200',
            'acronym'             => 'required|string|max:20',
            'seta_id'             => 'nullable|exists:lms_setas,id',
            'registration_number' => 'nullable|string|max:60|unique:lms_aqps,registration_number',
            'website'             => 'nullable|string|max:200',
            'contact_email'       => 'nullable|email|max:150',
            'contact_phone'       => 'nullable|string|max:30',
            'is_active'           => 'boolean',
            'notes'               => 'nullable|string',
        ]);

        LmsAqp::create($data);

        return back()->with('success', 'AQP saved.');
    }

    public function update(Request $request, LmsAqp $aqp)
    {
        $data = $request->validate([
            'name'                => 'required|string|max:200',
            'acronym'             => 'required|string|max:20',
            'seta_id'             => 'nullable|exists:lms_setas,id',
            'registration_number' => 'nullable|string|max:60|unique:lms_aqps,registration_number,' . $aqp->id,
            'website'             => 'nullable|string|max:200',
            'contact_email'       => 'nullable|email|max:150',
            'contact_phone'       => 'nullable|string|max:30',
            'is_active'           => 'boolean',
            'notes'               => 'nullable|string',
        ]);

        $aqp->update($data);

        return back()->with('success', 'AQP updated.');
    }

    public function destroy(LmsAqp $aqp)
    {
        $aqp->delete();
        return back()->with('success', 'AQP deleted.');
    }

    // ── EISA Schedules ─────────────────────────────────────────────────────────

    public function storeEisa(Request $request)
    {
        $data = $request->validate([
            'learnership_id' => 'required|exists:lms_learnerships,id',
            'aqp_id'         => 'required|exists:lms_aqps,id',
            'hr_person_id'   => 'required|exists:hr_people,id',
            'scheduled_date' => 'required|date',
            'venue'          => 'nullable|string|max:200',
            'aqp_reference'  => 'nullable|string|max:80',
            'notes'          => 'nullable|string',
        ]);

        // QCTO EISA Policy: learner must have completed K, P, and WE before sitting EISA.
        $enrolment = LmsLearnershipEnrolment::where('learnership_id', $data['learnership_id'])
            ->where('hr_person_id', $data['hr_person_id'])
            ->whereIn('status', ['active', 'registered'])
            ->latest('start_date')
            ->first();

        if ($enrolment && !$enrolment->isAllComponentsComplete()) {
            $missing = [];
            if (!$enrolment->k_completed_at)      $missing[] = 'Knowledge component';
            if (!$enrolment->p_completed_at)      $missing[] = 'Practical component';
            if (!$enrolment->we_logbook_complete) $missing[] = 'Workplace experience logbook';

            return back()->withErrors([
                'hr_person_id' => 'Learner has not completed all required components before EISA: ' . implode(', ', $missing) . '.',
            ]);
        }

        $data['status']      = 'scheduled';
        $data['captured_by'] = auth()->id();

        $schedule = LmsEisaSchedule::create($data);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($schedule)
            ->withProperties(['learnership_id' => $data['learnership_id'], 'hr_person_id' => $data['hr_person_id']])
            ->log('EISA scheduled for learner #' . $data['hr_person_id'] . ' on ' . $data['scheduled_date'] . '.');

        return back()->with('success', 'EISA scheduled.');
    }

    public function updateEisa(Request $request, LmsEisaSchedule $eisa)
    {
        $data = $request->validate([
            'scheduled_date'       => 'required|date',
            'venue'                => 'nullable|string|max:200',
            'status'               => 'required|in:scheduled,completed,absent,deferred,cancelled',
            'score'                => 'nullable|numeric|min:0|max:100',
            'result'               => 'nullable|in:competent,not_yet_competent,absent',
            'certificate_number'   => 'nullable|string|max:80',
            'certificate_issued_at'=> 'nullable|date',
            'aqp_reference'        => 'nullable|string|max:80',
            'notes'                => 'nullable|string',
        ]);

        $eisa->update($data);

        // When EISA result is competent, mark the learnership enrolment as completed
        // and issue the QCTO occupational certificate record.
        if (($data['result'] ?? null) === 'competent' && $eisa->learnership_id && $eisa->hr_person_id) {
            $enrolment = LmsLearnershipEnrolment::where('learnership_id', $eisa->learnership_id)
                ->where('hr_person_id', $eisa->hr_person_id)
                ->whereIn('status', ['active', 'registered'])
                ->latest('start_date')
                ->first();

            if ($enrolment) {
                $enrolment->update([
                    'status'                => 'completed',
                    'passed'                => true,
                    'certificate_number'    => $data['certificate_number'] ?? $enrolment->certificate_number,
                    'certificate_issued_at' => $data['certificate_issued_at'] ?? $enrolment->certificate_issued_at,
                ]);

                app(LmsBbeeService::class)->recordLearnershipSpend($enrolment);

                activity()
                    ->causedBy(auth()->user())
                    ->performedOn($enrolment)
                    ->withProperties(['certificate_number' => $data['certificate_number'] ?? null, 'eisa_id' => $eisa->id])
                    ->log('Learnership enrolment #' . $enrolment->id . ' marked completed via competent EISA result.');
            }
        }

        activity()
            ->causedBy(auth()->user())
            ->performedOn($eisa)
            ->withProperties(['status' => $data['status'], 'result' => $data['result'] ?? null])
            ->log('EISA #' . $eisa->id . ' updated — status: ' . $data['status'] . ($data['result'] ? ', result: ' . $data['result'] : '') . '.');

        return back()->with('success', 'EISA record updated.');
    }

    public function destroyEisa(LmsEisaSchedule $eisa)
    {
        $eisa->delete();
        return back()->with('success', 'EISA record deleted.');
    }
}
