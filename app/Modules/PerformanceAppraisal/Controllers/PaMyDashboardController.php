<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\Pa360Rater;
use App\Modules\PerformanceAppraisal\Models\Pa360Review;
use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use App\Modules\PerformanceAppraisal\Models\PaCoachingSession;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentPlan;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Modules\PerformanceAppraisal\Models\PaPipPlan;
use App\Modules\PerformanceAppraisal\Models\PaProbationCheckin;
use App\Modules\PerformanceAppraisal\Models\PaProbationFeedback;
use App\Modules\PerformanceAppraisal\Models\PaProbationReview;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaMyDashboardController extends Controller
{
    public function index(): Response
    {
        $userId = Auth::id();

        $myPersonId = DB::table('hr_people')->where('user_id', $userId)->value('id');
        $hasReports = $myPersonId
            ? DB::table('hr_people')->where('manager_id', $myPersonId)->exists()
            : false;

        // Latest performance snapshot
        $snapshot = PaPerformanceSnapshot::where('employee_id', $userId)
            ->with('cycle:id,name,status')
            ->latest('snapshot_date')
            ->first();

        // Active KPIs with recent progress logs
        $kpis = PaEmployeeKpi::where('employee_id', $userId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->with(['cycle:id,name', 'progressLogs' => fn($q) => $q->latest('log_date'), 'score'])
            ->withCount('progressLogs')
            ->get()
            ->map(fn($k) => [
                'id'                  => $k->id,
                'title'               => $k->title,
                'target_value'        => $k->target_value,
                'unit'                => $k->unit,
                'weighting'           => $k->weighting,
                'status'              => $k->status,
                'cycle'               => $k->cycle?->name,
                'current'             => $k->latestValue(),
                'score_pct'           => $k->score?->score_pct,
                'self_update_allowed' => (bool) $k->self_update_allowed,
                'progress_logs_count' => $k->progress_logs_count,
                'recent_logs'         => $k->progressLogs->take(5)->map(fn($l) => [
                    'value'    => $l->value,
                    'notes'    => $l->notes,
                    'log_date' => $l->log_date->format('d M Y'),
                ])->values(),
            ]);

        // Upcoming check-ins (next 30 days)
        $checkins = PaCheckin::where('employee_id', $userId)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'id'           => $c->id,
                'title'        => $c->title,
                'scheduled_at' => $c->scheduled_at?->format('d M Y H:i'),
                'location'     => $c->location,
                'status'       => $c->status,
                'cycle_id'     => $c->cycle_id,
            ]);

        // Recent feedback (last 10, non-draft)
        $feedback = PaFeedback::where('employee_id', $userId)
            ->where('is_draft', false)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($f) => [
                'id'              => $f->id,
                'feedback_type'   => $f->feedback_type,
                'content'         => $f->content,
                'rating'          => $f->rating,
                'is_anonymous'    => $f->is_anonymous,
                'author'          => $f->is_anonymous ? null : $f->author_name,
                'acknowledged_at' => $f->acknowledged_at?->format('d M Y'),
                'created_at'      => $f->created_at?->format('d M Y'),
            ]);

        // Coaching sessions
        $coaching = PaCoachingSession::where('employee_id', $userId)
            ->orderByDesc('session_date')
            ->limit(10)
            ->get()
            ->map(fn($s) => [
                'id'           => $s->id,
                'title'        => $s->title,
                'session_date' => $s->session_date?->format('d M Y'),
                'status'       => $s->status,
                'objectives'   => $s->objectives,
                'outcomes'     => $s->outcomes,
            ]);

        // Active PIP
        $pip = PaPipPlan::where('employee_id', $userId)
            ->where('status', 'active')
            ->with('objectives')
            ->first();

        $pipData = $pip ? [
            'id'             => $pip->id,
            'title'          => $pip->title ?? 'PIP',
            'start_date'     => $pip->start_date?->format('d M Y'),
            'target_end'     => $pip->target_end_date?->format('d M Y'),
            'status'         => $pip->status,
            'days_remaining' => $pip->daysRemaining(),
            'progress'       => $pip->overallProgress(),
            'objectives_met' => $pip->objectives->where('status', 'met')->count(),
            'objectives_total' => $pip->objectives->count(),
        ] : null;

        // 360 reviews
        $reviews360 = Pa360Review::where('employee_id', $userId)
            ->with('cycle:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'id'              => $r->id,
                'title'           => $r->title,
                'status'          => $r->status,
                'cycle'           => $r->cycle?->name,
                'due_date'        => $r->due_date?->format('d M Y'),
                'completion_rate' => $r->completionRate(),
            ]);

        // Development plans
        $development = PaDevelopmentPlan::where('employee_id', $userId)
            ->with('cycle:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($d) => [
                'id'         => $d->id,
                'title'      => $d->title,
                'cycle'      => $d->cycle?->name,
                'created_at' => $d->created_at?->format('d M Y'),
            ]);

        // Probation status + check-ins + feedback
        $probation = PaProbationReview::where('employee_id', $userId)
            ->orderByDesc('created_at')
            ->first();

        $probationCheckins = $probation
            ? PaProbationCheckin::where('review_id', $probation->id)
                ->where('status', 'scheduled')
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at')
                ->limit(10)
                ->get()
                ->map(fn($c) => [
                    'id'           => $c->id,
                    'checkin_type' => $c->checkin_type,
                    'scheduled_at' => $c->scheduled_at?->format('d M Y H:i'),
                    'agenda'       => $c->agenda,
                    'status'       => $c->status,
                ])
            : collect();

        $probationFeedback = $probation
            ? PaProbationFeedback::where('review_id', $probation->id)
                ->where('is_draft', false)
                ->with('author.person')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn($f) => [
                    'id'            => $f->id,
                    'feedback_type' => $f->feedback_type,
                    'content'       => $f->content,
                    'rating'        => $f->rating,
                    'author'        => $this->resolveAuthorName($f),
                    'created_at'    => $f->created_at?->format('d M Y'),
                ])
            : collect();

        // Pending 360 reviews where this user is an invited rater
        $pending360 = Pa360Rater::where('rater_id', $userId)
            ->where('status', 'pending')
            ->with('review:id,title,due_date,status,require_anonymity')
            ->get()
            ->filter(fn($r) => $r->review && $r->review->status === 'collecting')
            ->map(fn($r) => [
                'rater_id'     => $r->id,
                'review_id'    => $r->review_id,
                'review_title' => $r->review->title,
                'relationship' => $r->relationship,
                'due_date'     => $r->review->due_date?->format('d M Y'),
            ])
            ->values();

        // Employee list for Give Feedback form
        $employees = User::leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->select('users.id', DB::raw("COALESCE(NULLIF(TRIM(CONCAT(hr_people.first_name,' ',hr_people.surname)),''), users.email) AS name"))
            ->where('users.id', '!=', $userId)
            ->orderByRaw("COALESCE(NULLIF(TRIM(CONCAT(hr_people.first_name,' ',hr_people.surname)),''), users.email)")
            ->get()
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name]);

        return Inertia::render('Modules/PerformanceAppraisal/MyDashboard/Index', [
            'snapshot'    => $snapshot ? [
                'id'                  => $snapshot->id,
                'overall_score'       => $snapshot->overall_score,
                'kpi_completion_rate' => $snapshot->kpi_completion_rate,
                'snapshot_date'       => $snapshot->snapshot_date?->format('d M Y'),
                'cycle'               => $snapshot->cycle?->name,
                'appraisal_status'    => $snapshot->appraisal_status,
                'employee_score'      => $snapshot->employee_score,
                'manager_score'       => $snapshot->manager_score,
                'score_360'           => $snapshot->score_360,
                'final_score'         => $snapshot->final_score,
                'employee_comments'   => $snapshot->employee_comments,
                'manager_comments'    => $snapshot->manager_comments,
                'submitted_at'        => $snapshot->submitted_at?->format('d M Y'),
                'approved_at'         => $snapshot->approved_at?->format('d M Y'),
            ] : null,
            'kpis'        => $kpis,
            'checkins'    => $checkins,
            'feedback'    => $feedback,
            'coaching'    => $coaching,
            'pip'         => $pipData,
            'reviews360'  => $reviews360,
            'pending360'  => $pending360,
            'development' => $development,
            'employees'   => $employees,
            'has_reports'        => $hasReports,
            'probation'          => $probation ? [
                'id'             => $probation->id,
                'probation_type' => $probation->probation_type,
                'status'         => $probation->status,
                'start_date'     => $probation->start_date?->format('d M Y'),
                'end_date'       => $probation->end_date?->format('d M Y'),
                'days_remaining' => $probation->daysRemaining(),
            ] : null,
            'probation_checkins' => $probationCheckins->values(),
            'probation_feedback' => $probationFeedback->values(),
        ]);
    }

    private function resolveAuthorName(PaProbationFeedback $f): string
    {
        $person = $f->author?->person;
        if ($person) {
            $name = trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''));
            if ($name) return $name;
        }
        return $f->author?->email ?? 'Unknown';
    }
}
