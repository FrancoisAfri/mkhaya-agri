<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function index(Job $job, Request $request): Response
    {
        $statuses = [
            ApplicationStatus::Applied->value,
            ApplicationStatus::Screening->value,
            ApplicationStatus::Assessment->value,
            ApplicationStatus::Interview->value,
            ApplicationStatus::Shortlisted->value,
            ApplicationStatus::OfferSent->value,
        ];

        // Aggregate counts in one query — avoids N+1 across status columns
        $counts = Application::where('job_id', $job->id)
            ->whereIn('status', $statuses)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $columns = [];

        foreach ($statuses as $status) {
            $query = Application::with([
                    'candidate:id,uuid,first_name,last_name,email',
                    'fitScore:application_id,overall_score',
                    'pipelineStage:id,name',
                ])
                ->where('job_id', $job->id)
                ->where('status', $status);

            // Keyset pagination per column
            $cursorKey = "cursor_{$status}";
            if ($request->filled($cursorKey)) {
                $query->where('id', '>', (int) $request->input($cursorKey));
            }

            $items = $query->orderBy('id')->limit(10)->get();

            $columns[] = [
                'status'      => $status,
                'label'       => ApplicationStatus::from($status)->label(),
                'count'       => (int) ($counts[$status] ?? 0),
                'has_more'    => $items->count() === 10,
                'next_cursor' => $items->last()?->id,
                'items'       => $items->map(fn($app) => [
                    'id'            => $app->id,
                    'candidate'     => [
                        'id'        => $app->candidate->id,
                        'full_name' => $app->candidate->full_name,
                        'email'     => $app->candidate->email,
                    ],
                    'current_stage' => $app->pipelineStage
                        ? ['name' => $app->pipelineStage->name]
                        : null,
                    'fit_score'  => $app->fitScore?->overall_score,
                    'created_at' => $app->created_at->toIso8601String(),
                ]),
            ];
        }

        return Inertia::render('Modules/Recruitment/Pipeline/Board', [
            'job'     => ['id' => $job->id, 'title' => $job->title],
            'columns' => $columns,
            'filters' => $request->only(array_map(fn($s) => "cursor_{$s}", $statuses)),
        ]);
    }
}
