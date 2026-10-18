<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Http\Resources\RequisitionListResource;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Interview;
use App\Modules\HR\Recruitment\Models\Job;
use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Inertia\Inertia;
use Inertia\Response;

class RecruitmentDashboardController
{
    public function index(): Response
    {
        $openApplications = Application::whereNotIn('status', [
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Withdrawn->value,
            ApplicationStatus::Hired->value,
        ])->count();

        $totalCandidates = Candidate::count();

        $openJobs = Job::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', now()))
            ->count();

        $interviewsToday = Interview::whereDate('scheduled_at', today())
            ->where('status', 'scheduled')
            ->count();

        $pendingOffers = Offer::where('status', 'sent')->count();

        $funnelRaw = Application::selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $funnel = collect(ApplicationStatus::cases())->map(fn($s) => [
            'status' => $s->value,
            'label'  => $s->label(),
            'count'  => $funnelRaw->get($s->value, 0),
            'color'  => $s->color(),
        ])->values();

        $sourceBreakdown = Application::selectRaw('source, count(*) as cnt')
            ->groupBy('source')
            ->orderByDesc('cnt')
            ->limit(8)
            ->get()
            ->map(fn($r) => ['source' => $r->source, 'count' => $r->cnt]);

        $recentHires = Application::with('candidate:id,first_name,last_name', 'job:id,title')
            ->where('status', ApplicationStatus::Hired->value)
            ->where('updated_at', '>=', now()->subDays(30))
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn($a) => [
                'candidate' => ['full_name' => $a->candidate->full_name ?? '—'],
                'job'       => ['title' => $a->job->title ?? '—'],
                'hired_at'  => $a->updated_at->toDateString(),
            ]);

        $stats = [
            'total_requisitions' => Requisition::count(),
            'pending_approval'   => Requisition::where('status', RequisitionStatus::PendingApproval->value)->count(),
            'active_vacancies'   => Vacancy::whereIn('status', [
                VacancyStatus::Published->value,
                VacancyStatus::InternalOnly->value,
                VacancyStatus::External->value,
            ])->count(),
            'open_applications'  => $openApplications,
            'total_candidates'   => $totalCandidates,
            'open_jobs'          => $openJobs,
            'interviews_today'   => $interviewsToday,
            'pending_offers'     => $pendingOffers,
        ];

        $recentRequisitions = Requisition::with(['requestedBy'])
            ->latest()
            ->limit(5)
            ->get();

        $recentVacancies = Vacancy::with(['createdBy'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($v) => [
                'id'             => $v->id,
                'uuid'           => $v->uuid,
                'vacancy_number' => $v->vacancy_number,
                'title'          => $v->title,
                'department'     => $v->department,
                'status'         => [
                    'value' => $v->status->value,
                    'label' => $v->status->label(),
                    'color' => $v->status->color(),
                ],
                'created_by' => $v->createdBy
                    ? ['id' => $v->createdBy->id, 'name' => $v->createdBy->name]
                    : null,
                'created_at' => $v->created_at->toDateString(),
            ]);

        return Inertia::render('Modules/Recruitment/Dashboard/Index', [
            'stats'               => $stats,
            'recentRequisitions'  => RequisitionListResource::collection($recentRequisitions),
            'recentVacancies'     => $recentVacancies,
            'funnel'              => $funnel,
            'sourceBreakdown'     => $sourceBreakdown,
            'recentHires'         => $recentHires,
        ]);
    }
}
