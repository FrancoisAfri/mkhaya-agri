<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmComplaint;
use App\Modules\CRM\Services\CrmComplaintService;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CrmComplaintController extends Controller
{
    public function __construct(
        private CrmComplaintService $complaints,
        private CrmVisibilityService $visibility,
    ) {
    }

    public function index(Request $request): Response
    {
        $visibleIds = $this->visibility->visibleOwnerIds();

        $query = CrmComplaint::with(['assignee.person', 'againstEmployee.person'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s));

        // Visibility: handlers/admins see all; otherwise their own + their team's.
        if ($visibleIds !== null) {
            $query->where(function ($q) use ($visibleIds) {
                $q->whereIn('assigned_to', $visibleIds)
                  ->orWhereIn('against_employee_id', $visibleIds)
                  ->orWhereIn('manager_id', $visibleIds);
            });
        }

        $complaints = $query->latest()->limit(500)->get()->map(fn ($c) => $this->present($c));

        return Inertia::render('Modules/CRM/Complaints/Index', [
            'complaints' => $complaints,
            'severities' => CrmComplaint::SEVERITIES,
            'statuses'   => CrmComplaint::STATUSES,
            'handlers'   => $this->handlerOptions(),
            'filters'    => $request->only('status', 'type', 'severity'),
            'openId'     => $request->integer('open') ?: null,
        ]);
    }

    public function show(CrmComplaint $complaint): \Illuminate\Http\JsonResponse
    {
        $complaint->load(['assignee.person', 'againstEmployee.person', 'manager.person', 'updates.user.person']);

        return response()->json([
            'complaint' => $this->present($complaint, true),
            'timeline'  => $complaint->updates->map(fn ($u) => [
                'type' => $u->type,
                'from' => $u->from_status,
                'to'   => $u->to_status,
                'note' => $u->note,
                'user' => $u->user?->person?->first_name,
                'at'   => $u->created_at?->diffForHumans(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject'        => 'required|string|max:250',
            'body'           => 'required|string',
            'customer_name'  => 'nullable|string|max:150',
            'customer_email' => 'nullable|email|max:190',
            'customer_phone' => 'nullable|string|max:40',
        ]);

        $this->complaints->create($data, 'manual');

        return back()->with('success', 'Complaint logged.');
    }

    public function update(Request $request, CrmComplaint $complaint): RedirectResponse
    {
        $data = $request->validate([
            'status'      => 'nullable|in:new,acknowledged,in_progress,escalated,resolved,closed',
            'assigned_to' => 'nullable|exists:users,id',
            'note'        => 'nullable|string|max:2000',
        ]);

        if (! empty($data['assigned_to']) && $data['assigned_to'] != $complaint->assigned_to) {
            $this->complaints->assign($complaint, (int) $data['assigned_to']);
        }
        if (! empty($data['status']) && $data['status'] !== $complaint->status) {
            $this->complaints->changeStatus($complaint, $data['status'], $data['note'] ?? null);
        } elseif (! empty($data['note'])) {
            $this->complaints->addNote($complaint, $data['note']);
        }

        return back()->with('success', 'Complaint updated.');
    }

    public function analyze(CrmComplaint $complaint): RedirectResponse
    {
        $this->complaints->runAi($complaint);
        return back()->with('success', 'AI analysis refreshed.');
    }

    public function destroy(CrmComplaint $complaint): RedirectResponse
    {
        $complaint->delete();
        return back()->with('success', 'Complaint removed.');
    }

    public function settings(): Response
    {
        $mailboxes = \App\Modules\CRM\Models\CrmMailbox::where('purpose', 'complaint')->latest()->get()
            ->map(fn ($m) => [
                'id'              => $m->id,
                'name'            => $m->name,
                'email'           => $m->email,
                'host'            => $m->host,
                'port'            => $m->port,
                'encryption'      => $m->encryption,
                'username'        => $m->username,
                'folder'          => $m->folder,
                'active'          => $m->active,
                'ai_filter'       => $m->ai_filter,
                'last_checked_at' => optional($m->last_checked_at)->diffForHumans(),
                'last_status'     => $m->last_status,
                'has_password'    => ! empty($m->getAttributes()['password']),
            ]);

        return Inertia::render('Modules/CRM/Complaints/Settings', [
            'settings'   => $this->complaints->settings(),
            'severities' => array_keys(CrmComplaint::SEVERITIES),
            'mailboxes'  => $mailboxes,
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'respond_hours'        => 'required|integer|min:1|max:720',
            'resolve_hours'        => 'required|integer|min:1|max:2160',
            'escalate_after_hours' => 'required|integer|min:1|max:720',
            'escalate_severities'  => 'array',
            'escalate_severities.*'=> 'in:low,medium,high,critical',
        ]);

        $this->complaints->saveSettings($data);

        return back()->with('success', 'Complaint settings saved.');
    }

    private function present(CrmComplaint $c, bool $full = false): array
    {
        $data = [
            'id'             => $c->id,
            'reference'      => $c->reference,
            'type'           => $c->type,
            'source'         => $c->source,
            'subject'        => $c->subject,
            'customer_name'  => $c->customer_name,
            'severity'       => $c->severity,
            'severity_label' => $c->severity ? (CrmComplaint::SEVERITIES[$c->severity]['label'] ?? $c->severity) : null,
            'severity_color' => $c->severity ? (CrmComplaint::SEVERITIES[$c->severity]['color'] ?? 'grey') : 'grey',
            'category'       => $c->category,
            'status'         => $c->status,
            'status_label'   => CrmComplaint::STATUSES[$c->status]['label'] ?? $c->status,
            'status_color'   => CrmComplaint::STATUSES[$c->status]['color'] ?? 'grey',
            'against'        => $c->againstEmployee?->person?->first_name ?? $c->against_name,
            'assignee'       => $c->assignee?->person?->first_name,
            'assigned_to'    => $c->assigned_to,
            'overdue'        => $c->isOverdue(),
            'created_at'     => $c->created_at?->diffForHumans(),
        ];

        if ($full) {
            $data += [
                'body'            => $c->body,
                'customer_email'  => $c->customer_email,
                'customer_phone'  => $c->customer_phone,
                'sentiment'       => $c->sentiment,
                'ai_summary'      => $c->ai_summary,
                'recommendations' => $c->ai_recommendations ?: [],
                'respond_by'      => $c->respond_by?->toDayDateTimeString(),
                'resolve_by'      => $c->resolve_by?->toDayDateTimeString(),
                'escalated'       => $c->escalated,
            ];
        }

        return $data;
    }

    private function handlerOptions(): array
    {
        return DB::table('users')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->select('users.id', DB::raw("CONCAT(hr_people.first_name, ' ', hr_people.surname) as name"))
            ->orderBy('hr_people.first_name')->limit(1000)->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
    }
}
