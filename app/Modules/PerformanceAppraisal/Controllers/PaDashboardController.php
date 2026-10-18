<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaTemplate;
use App\Modules\PerformanceAppraisal\Models\PaRatingScale;
use Inertia\Inertia;
use Inertia\Response;

class PaDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/Dashboard/Index', [
            'stats' => [
                'total_cycles'    => PaCycle::count(),
                'active_cycles'   => PaCycle::where('status', 'active')->count(),
                'total_templates' => PaTemplate::where('is_active', true)->count(),
                'total_scales'    => PaRatingScale::where('is_active', true)->count(),
            ],
            'recentCycles' => PaCycle::with(['ratingScale', 'template'])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn($c) => [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'cycle_type' => $c->cycle_type,
                    'status'     => $c->status,
                    'start_date' => $c->start_date?->format('d M Y'),
                    'end_date'   => $c->end_date?->format('d M Y'),
                ]),
        ]);
    }
}
