<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Compliance\Models\DataBreachIncident;
use App\Core\Compliance\Models\PhiAccessLog;
use App\Core\Compliance\Models\PopiaConsent;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/Compliance/Dashboard/Index', [
            'stats'          => $this->stats(),
            'openBreaches'   => $this->openBreaches(),
            'recentPhiLog'   => $this->recentPhiLog(),
        ]);
    }

    private function stats(): array
    {
        $openBreaches    = DataBreachIncident::whereIn('status', ['open', 'notified'])->count();
        $overdueBreaches = DataBreachIncident::where('status', 'open')
            ->where('detected_at', '<=', now()->subHours(72))
            ->whereNull('notified_regulator_at')
            ->count();

        $phiAccessLast7d = PhiAccessLog::where('accessed_at', '>=', now()->subDays(7))->count();

        $activeConsents = PopiaConsent::where('status', 'granted')
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        return compact('openBreaches', 'overdueBreaches', 'phiAccessLast7d', 'activeConsents');
    }

    private function openBreaches(): array
    {
        return DataBreachIncident::with(['reportedBy.person', 'assignedTo.person'])
            ->whereIn('status', ['open', 'notified', 'contained'])
            ->orderByDesc('detected_at')
            ->limit(10)
            ->get()
            ->map(fn ($i) => [
                'uuid'              => $i->uuid,
                'title'             => $i->title,
                'severity'          => $i->severity,
                'status'            => $i->status,
                'detected_at'       => $i->detected_at->toISOString(),
                'hours_until_deadline' => $i->hoursUntilDeadline(),
                'is_overdue'        => $i->isOverdue(),
                'reported_by'       => $i->reportedBy?->name,
                'assigned_to'       => $i->assignedTo?->name,
            ])
            ->toArray();
    }

    private function recentPhiLog(): array
    {
        return PhiAccessLog::with('accessor.person')
            ->orderByDesc('accessed_at')
            ->limit(15)
            ->get()
            ->map(fn ($l) => [
                'id'           => $l->id,
                'subject_type' => class_basename($l->subject_type),
                'subject_id'   => $l->subject_id,
                'field'        => $l->field,
                'accessed_by'  => $l->accessor?->name ?? 'System',
                'ip_address'   => $l->ip_address,
                'accessed_at'  => $l->accessed_at->toISOString(),
            ])
            ->toArray();
    }
}
