<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\CertificationExpiryLog;
use App\Modules\HR\LMS\Models\EmployeeCertification;
use App\Modules\HR\LMS\Models\LmsCertificateTemplate;
use App\Modules\HR\LMS\Models\LmsCertification;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Services\LmsCertificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsCertificationController extends Controller
{
    public function __construct(private readonly LmsCertificationService $service) {}

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function dashboard(Request $request): Response
    {
        $filters       = $request->only(['certification_id', 'days_window']);
        $dashboard     = $this->service->getCertificationDashboard($filters);
        $certifications= LmsCertification::with('course')->orderBy('name')->get(['id', 'name', 'course_id']);

        return Inertia::render('Modules/LMS/Admin/CertificationDashboard', [
            'kpi'              => $dashboard['kpi'],
            'byCertification'  => $dashboard['byCertification'],
            'expiringSoon'     => $dashboard['expiringSoon'],
            'recentIssued'     => $dashboard['recentIssued'],
            'recentExpired'    => $dashboard['recentExpired'],
            'certifications'   => $certifications,
            'filters'          => $filters,
        ]);
    }

    // ── Certificate templates ─────────────────────────────────────────────────

    public function templatesIndex(Request $request): Response
    {
        $templates = LmsCertificateTemplate::with(['course', 'createdBy'])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%' . $request->search . '%'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $courses = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);

        return Inertia::render('Modules/LMS/Admin/CertificationDashboard', [
            'templates'    => $templates,
            'courses'      => $courses,
            'activeTab'    => 'templates',
            'filters'      => $request->only(['search']),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                         => 'required|string|max:160',
            'description'                  => 'nullable|string',
            'course_id'                    => 'nullable|exists:lms_courses,id',
            'orientation'                  => 'required|in:landscape,portrait',
            'background_color'             => 'nullable|string|max:10',
            'accent_color'                 => 'nullable|string|max:10',
            'title_text'                   => 'nullable|string|max:160',
            'subtitle_text'                => 'nullable|string|max:200',
            'body_html'                    => 'nullable|string',
            'signatory_1_name'             => 'nullable|string|max:100',
            'signatory_1_title'            => 'nullable|string|max:100',
            'signatory_2_name'             => 'nullable|string|max:100',
            'signatory_2_title'            => 'nullable|string|max:100',
            'requires_understanding_score' => 'boolean',
            'min_understanding_score'      => 'nullable|integer|min:0|max:100',
            'is_active'                    => 'boolean',
        ]);

        $this->service->createCertificateTemplate($data, Auth::id());

        return redirect()->back()->with('success', 'Template created.');
    }

    public function updateTemplate(Request $request, LmsCertificateTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name'                         => 'required|string|max:160',
            'description'                  => 'nullable|string',
            'course_id'                    => 'nullable|exists:lms_courses,id',
            'orientation'                  => 'required|in:landscape,portrait',
            'background_color'             => 'nullable|string|max:10',
            'accent_color'                 => 'nullable|string|max:10',
            'title_text'                   => 'nullable|string|max:160',
            'subtitle_text'                => 'nullable|string|max:200',
            'body_html'                    => 'nullable|string',
            'signatory_1_name'             => 'nullable|string|max:100',
            'signatory_1_title'            => 'nullable|string|max:100',
            'signatory_2_name'             => 'nullable|string|max:100',
            'signatory_2_title'            => 'nullable|string|max:100',
            'requires_understanding_score' => 'boolean',
            'min_understanding_score'      => 'nullable|integer|min:0|max:100',
            'is_active'                    => 'boolean',
        ]);

        $this->service->updateCertificateTemplate($template, $data);

        return redirect()->back()->with('success', 'Template updated.');
    }

    public function destroyTemplate(LmsCertificateTemplate $template): RedirectResponse
    {
        $template->delete();
        return redirect()->back()->with('success', 'Template deleted.');
    }

    // ── Certification programs ─────────────────────────────────────────────────

    public function certificationsIndex(Request $request): Response
    {
        $certifications = LmsCertification::with(['course', 'template', 'refresherCourse'])
            ->withCount([
                'employeeCertifications as active_count'  => fn ($q) => $q->where('status', 'active'),
                'employeeCertifications as expired_count' => fn ($q) => $q->where('status', 'expired'),
            ])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%' . $request->search . '%'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $courses   = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);
        $templates = LmsCertificateTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Modules/LMS/Admin/CertificationDashboard', [
            'certifications' => $certifications,
            'courses'        => $courses,
            'templates'      => $templates,
            'activeTab'      => 'certifications',
            'filters'        => $request->only(['search']),
        ]);
    }

    public function storeCertification(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                          => 'required|string|max:160',
            'description'                   => 'nullable|string',
            'course_id'                     => 'required|exists:lms_courses,id',
            'template_id'                   => 'nullable|exists:lms_certificate_templates,id',
            'valid_days'                    => 'nullable|integer|min:1',
            'refresher_before_days'         => 'nullable|integer|min:1|max:365',
            'refresher_course_id'           => 'nullable|exists:lms_courses,id',
            'reminder_days'                 => 'nullable|array',
            'reminder_days.*'               => 'integer|min:1',
            'actions_blocked_when_expired'  => 'nullable|array',
            'actions_blocked_when_expired.*'=> 'string',
            'requires_understanding_score'  => 'boolean',
            'min_understanding_score'       => 'nullable|integer|min:0|max:100',
            'auto_issue'                    => 'boolean',
            'is_active'                     => 'boolean',
        ]);

        $this->service->createCertificationProgram($data, Auth::id());

        return redirect()->back()->with('success', 'Certification program created.');
    }

    public function updateCertification(Request $request, LmsCertification $certification): RedirectResponse
    {
        $data = $request->validate([
            'name'                          => 'required|string|max:160',
            'description'                   => 'nullable|string',
            'template_id'                   => 'nullable|exists:lms_certificate_templates,id',
            'valid_days'                    => 'nullable|integer|min:1',
            'refresher_before_days'         => 'nullable|integer|min:1|max:365',
            'refresher_course_id'           => 'nullable|exists:lms_courses,id',
            'reminder_days'                 => 'nullable|array',
            'reminder_days.*'               => 'integer|min:1',
            'actions_blocked_when_expired'  => 'nullable|array',
            'actions_blocked_when_expired.*'=> 'string',
            'requires_understanding_score'  => 'boolean',
            'min_understanding_score'       => 'nullable|integer|min:0|max:100',
            'auto_issue'                    => 'boolean',
            'is_active'                     => 'boolean',
        ]);

        $certification->update($data);

        return redirect()->back()->with('success', 'Certification program updated.');
    }

    public function destroyCertification(LmsCertification $certification): RedirectResponse
    {
        $certification->delete();
        return redirect()->back()->with('success', 'Certification program deleted.');
    }

    // ── Run all certification checks (manual trigger) ─────────────────────────

    public function runChecks(): RedirectResponse
    {
        $expired   = $this->service->trackCertificateExpiry();
        $reminders = $this->service->sendCertificateExpiryReminder();
        $refreshers= $this->service->assignRefresherTraining(actorId: Auth::id());

        return redirect()->back()->with('success',
            "Checks complete: {$expired} expired, {$reminders} reminders sent, {$refreshers} refreshers assigned.");
    }

    // ── Force issue / revoke ──────────────────────────────────────────────────

    public function issueCertificate(Request $request): RedirectResponse
    {
        $request->validate(['enrollment_id' => 'required|exists:lms_enrollments,id']);

        $enrollment = LmsEnrollment::findOrFail($request->enrollment_id);
        $this->service->issueTrainingCertificate($enrollment, Auth::id());

        return redirect()->back()->with('success', 'Certificate issued.');
    }

    public function revokeCertificate(Request $request, EmployeeCertification $employeeCertification): RedirectResponse
    {
        $request->validate(['reason' => 'required|string']);
        $this->service->revokeCertificate($employeeCertification, $request->reason, Auth::id());

        return redirect()->back()->with('success', 'Certificate revoked.');
    }

    public function assignRefresher(EmployeeCertification $employeeCertification): RedirectResponse
    {
        $this->service->assignRefresherTraining($employeeCertification, Auth::id());
        return redirect()->back()->with('success', 'Refresher training assigned.');
    }

    // ── Expiry log for a single employee cert ─────────────────────────────────

    public function expiryLog(EmployeeCertification $employeeCertification): Response
    {
        $logs = CertificationExpiryLog::where('employee_certification_id', $employeeCertification->id)
            ->with(['createdBy.person'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($l) => [
                'event_type'       => $l->event_type,
                'event_label'      => CertificationExpiryLog::EVENT_TYPES[$l->event_type] ?? $l->event_type,
                'event_color'      => $l->eventColor(),
                'days_until_expiry'=> $l->days_until_expiry,
                'action_key'       => $l->action_key,
                'notes'            => $l->notes,
                'created_at'       => $l->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Modules/LMS/Admin/CertificationDashboard', [
            'expiryLog'          => $logs,
            'employeeCertification' => $employeeCertification->load(['user.person', 'certification.course']),
            'activeTab'          => 'expiry-log',
        ]);
    }
}
