<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceAuditController extends Controller
{
    public function index(Request $request)
    {
        $query = AttendanceAuditLog::query()->latest();

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $logs = $query->paginate(50)->withQueryString();

        $actionCounts = AttendanceAuditLog::recent(30)
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        $actions = AttendanceAuditLog::ACTIONS;

        return Inertia::render('HR/Attendance/Audit/Index', compact('logs', 'actionCounts', 'actions'));
    }
}
