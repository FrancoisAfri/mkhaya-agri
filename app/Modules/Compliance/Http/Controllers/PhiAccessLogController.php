<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Compliance\Models\PhiAccessLog;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PhiAccessLogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = PhiAccessLog::with('accessor.person')
            ->orderByDesc('accessed_at');

        if ($userId = $request->input('accessed_by')) {
            $query->where('accessed_by', $userId);
        }

        if ($subjectType = $request->input('subject_type')) {
            $query->where('subject_type', 'ilike', "%{$subjectType}%");
        }

        if ($from = $request->input('from')) {
            $query->whereDate('accessed_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('accessed_at', '<=', $to);
        }

        $logs = $query->paginate(50)->through(fn ($l) => [
            'id'           => $l->id,
            'subject_type' => class_basename($l->subject_type),
            'subject_id'   => $l->subject_id,
            'field'        => $l->field,
            'accessed_by'  => $l->accessor?->name ?? 'System',
            'ip_address'   => $l->ip_address,
            'user_agent'   => $l->user_agent,
            'accessed_at'  => $l->accessed_at->toISOString(),
        ]);

        return Inertia::render('Modules/Compliance/PhiLog/Index', [
            'logs'    => $logs,
            'filters' => $request->only(['accessed_by', 'subject_type', 'from', 'to']),
            'agents'  => User::where('status', 1)->with('person:id,user_id,first_name,surname')->get(['id'])->sortBy('name')->values()->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }
}
