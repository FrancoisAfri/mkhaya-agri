<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\Qcto\LmsAqp;
use App\Modules\HR\LMS\Models\Qcto\LmsAtrReport;
use App\Modules\HR\LMS\Models\Qcto\LmsEisaSchedule;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnership;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use App\Modules\HR\LMS\Models\Qcto\LmsModerationReport;
use App\Modules\HR\LMS\Models\Qcto\LmsNlrdSubmission;
use App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsSdf;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use App\Modules\HR\LMS\Models\Qcto\LmsWspPlan;
use Inertia\Inertia;

class LmsQctoAdminController extends Controller
{
    public function dashboard()
    {
        $currentYear = now()->year;

        $stats = [
            'registered_courses'     => LmsCourse::where('is_qcto_registered', true)->count(),
            'active_learnerships'    => LmsLearnership::where('is_active', true)->count(),
            'active_enrolments'      => LmsLearnershipEnrolment::whereIn('status', ['registered','active'])->count(),
            'registered_assessors'   => LmsRegisteredPerson::assessors()->active()->count(),
            'registered_moderators'  => LmsRegisteredPerson::moderators()->active()->count(),
            'active_sdfs'            => LmsSdf::active()->count(),
            'pending_wsp'            => LmsWspPlan::where('status', 'draft')->where('plan_year', $currentYear)->count(),
            'pending_atr'            => LmsAtrReport::where('status', 'draft')->where('report_year', $currentYear - 1)->count(),
            'pending_moderation'     => LmsModerationReport::where('status', 'draft')->count(),
            'pending_nlrd'           => LmsNlrdSubmission::where('status', 'draft')->count(),
            'expiring_assessors'     => LmsRegisteredPerson::where('expires_at', '<=', now()->addMonths(3))
                ->where('status', 'active')->count(),
            'expiring_sdfs'          => LmsSdf::where('expires_at', '<=', now()->addMonths(3))
                ->where('status', 'active')->count(),
            'active_aqps'            => LmsAqp::where('is_active', true)->count(),
            'pending_eisas'          => LmsEisaSchedule::where('status', 'scheduled')->count(),
        ];

        $setas = LmsSeta::orderBy('acronym')->get([
            'id', 'name', 'acronym', 'description', 'website',
            'levy_code', 'contact_email', 'contact_phone', 'is_active',
        ]);

        $upcomingDeadlines = $this->buildDeadlines();

        $primarySdf = LmsSdf::active()->where('is_primary', true)
            ->with('user', 'seta')
            ->first();

        return Inertia::render('Modules/LMS/Admin/QctoDashboard', [
            'stats'             => $stats,
            'setas'             => $setas,
            'upcomingDeadlines' => $upcomingDeadlines,
            'primarySdf'        => $primarySdf,
            'currentYear'       => $currentYear,
        ]);
    }

    // ── SETA CRUD ─────────────────────────────────────────────────────────────

    public function setaStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:200',
            'acronym'       => 'required|string|max:30',
            'description'   => 'nullable|string|max:1000',
            'website'       => 'nullable|string|max:200',
            'levy_code'     => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:200',
            'contact_phone' => 'nullable|string|max:30',
            'is_active'     => 'boolean',
        ]);
        LmsSeta::create($data);
        return back()->with('success', 'SETA added successfully.');
    }

    public function setaUpdate(Request $request, LmsSeta $seta): RedirectResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:200',
            'acronym'       => 'required|string|max:30',
            'description'   => 'nullable|string|max:1000',
            'website'       => 'nullable|string|max:200',
            'levy_code'     => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:200',
            'contact_phone' => 'nullable|string|max:30',
            'is_active'     => 'boolean',
        ]);
        $seta->update($data);
        return back()->with('success', 'SETA updated successfully.');
    }

    public function setaDestroy(LmsSeta $seta): RedirectResponse
    {
        $seta->delete();
        return back()->with('success', 'SETA removed.');
    }

    private function buildDeadlines(): array
    {
        $deadlines = [];

        // WSP due 30 April each year
        $wspDue = now()->year . '-04-30';
        if (now()->lt(now()->parse($wspDue))) {
            $deadlines[] = [
                'label'    => 'WSP Submission Due',
                'date'     => $wspDue,
                'type'     => 'wsp',
                'urgency'  => now()->diffInDays($wspDue) <= 30 ? 'high' : 'medium',
            ];
        }

        // ATR due 30 April (previous year's ATR)
        $atrDue = now()->year . '-04-30';
        if (now()->lt(now()->parse($atrDue))) {
            $deadlines[] = [
                'label'    => 'ATR Submission Due',
                'date'     => $atrDue,
                'type'     => 'atr',
                'urgency'  => now()->diffInDays($atrDue) <= 30 ? 'high' : 'medium',
            ];
        }

        // Expiring assessors / moderators in next 90 days
        $expiring = LmsRegisteredPerson::where('expires_at', '<=', now()->addDays(90))
            ->where('status', 'active')
            ->get(['first_name', 'last_name', 'person_type', 'expires_at']);

        foreach ($expiring as $person) {
            $deadlines[] = [
                'label'   => "{$person->full_name} ({$person->person_type}) registration expires",
                'date'    => $person->expires_at->format('Y-m-d'),
                'type'    => 'registration',
                'urgency' => $person->expires_at->isPast() ? 'critical' : 'high',
            ];
        }

        usort($deadlines, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $deadlines;
    }
}
