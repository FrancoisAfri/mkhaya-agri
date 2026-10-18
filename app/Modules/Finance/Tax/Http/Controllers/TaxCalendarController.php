<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxComplianceDeadline;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Finance\Tax\Services\ComplianceCalendarService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §14 Compliance calendar. */
class TaxCalendarController extends TaxController
{
    public function __construct(private readonly ComplianceCalendarService $calendar)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $f = array_filter($request->only(['status', 'tax_type', 'owner', 'from', 'to']));
        $names = collect($this->people())->pluck('name', 'id');

        $business = $this->businessFilter();
        $rows = TaxComplianceDeadline::query()->with(['taxType:id,code', 'period:id,uuid,label,status'])->forBusiness($business)
            ->when($f['status'] ?? null, fn ($q, $s) => $s === 'outstanding' ? $q->whereIn('status', ['open', 'in_progress', 'overdue']) : $q->where('status', $s))
            ->when($f['tax_type'] ?? null, fn ($q, $c) => $q->whereHas('taxType', fn ($t) => $t->where('code', $c)))
            ->when($f['owner'] ?? null, fn ($q, $o) => $q->where('owner_id', $o))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '<=', $d))
            ->orderBy('due_date')->limit(500)->get();

        return Inertia::render('Modules/Finance/Tax/Calendar', [
            'deadlines' => $rows->map(fn (TaxComplianceDeadline $d) => [
                'uuid' => $d->uuid, 'title' => $d->title, 'kind' => $d->kind, 'tax_type' => $d->taxType?->code,
                'entity' => $this->entityName($d->entity_id), 'due_date' => $d->due_date->toDateString(),
                'days_left' => (int) today()->diffInDays($d->due_date, false), 'status' => $d->status, 'source' => $d->source,
                'owner_id' => $d->owner_id, 'reviewer_id' => $d->reviewer_id, 'escalation_id' => $d->escalation_id,
                'owner' => $names[$d->owner_id] ?? null, 'reviewer' => $names[$d->reviewer_id] ?? null,
                'period' => $d->period ? ['uuid' => $d->period->uuid, 'label' => $d->period->label, 'status' => $d->period->status] : null,
                'resolution_note' => $d->resolution_note, 'completed_at' => $d->completed_at?->toDateTimeString(),
                'reminders_sent' => array_keys($d->reminders_sent ?? []),
            ])->values(),
            'filters' => $f,
            'people' => $this->people(),
            'taxTypes' => TaxType::orderBy('id')->get(['id', 'code', 'name']),
            'can' => ['manage' => request()->user()->can(TaxPermissions::MANAGE_CALENDAR)],
        ] + $this->businessProps($business));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::MANAGE_CALENDAR, 'deadline.create');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'due_date' => ['required', 'date'],
            'tax_type_id' => ['nullable', 'integer', 'exists:tax_types,id'], 'entity_id' => ['nullable', 'integer', $this->businessRule()],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'], 'reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'escalation_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return $this->attempt(fn () => $this->calendar->createManual($data), 'Deadline added.');
    }

    public function reschedule(Request $request, TaxComplianceDeadline $deadline): RedirectResponse
    {
        $this->allow(TaxPermissions::MANAGE_CALENDAR, 'deadline.reschedule');
        $data = $request->validate(['due_date' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:2000'], 'verified' => ['boolean']]);

        return $this->attempt(fn () => $this->calendar->reschedule($deadline, $data['due_date'], $data['reason'], (bool) ($data['verified'] ?? false)), 'Deadline moved.');
    }

    public function assign(Request $request, TaxComplianceDeadline $deadline): RedirectResponse
    {
        $this->allow(TaxPermissions::MANAGE_CALENDAR, 'deadline.assign');
        $data = $request->validate(['owner_id' => ['nullable', 'integer', 'exists:users,id'], 'reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'escalation_id' => ['nullable', 'integer', 'exists:users,id']]);

        return $this->attempt(fn () => $this->calendar->assign($deadline, $data), 'Assigned.');
    }

    public function complete(Request $request, TaxComplianceDeadline $deadline): RedirectResponse
    {
        $user = $request->user();
        if (! $user->can(TaxPermissions::MANAGE_CALENDAR) && $deadline->owner_id !== $user->id) {
            $this->allow(TaxPermissions::MANAGE_CALENDAR, 'deadline.complete');
        }
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->attempt(fn () => $this->calendar->complete($deadline, $data['note']), 'Marked done.');
    }
}
