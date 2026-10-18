<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Statutory Obligations queue (SHEQ-REG-5).
 *
 * This screen is the module's reason for existing. Every other SHEQ product records that an
 * incident happened; this one tells a practitioner that the Provincial Director must be notified in
 * eleven hours, and marks it BREACHED when they are not.
 *
 * Overdue is computed at read time rather than stored, so an obligation cannot sit at PENDING past
 * its deadline because a scheduled job did not run. A missed statutory deadline that the system
 * failed to show is worse than one it shouted about.
 */
class StatutoryObligationController extends Controller
{
    use ResolvesCompany;

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $now = Carbon::now();

        // Without a company there is nothing to show; listing every tenant's statutory position
        // would be a cross-customer leak (see ResolvesCompany).
        $rows = $companyId === null ? collect() : DB::table('sheq_statutory_obligations as o')
            ->leftJoin('sheq_incidents as i', function ($join) {
                $join->on('i.id', '=', 'o.subject_id')->where('o.subject_type', '=', 'INCIDENT');
            })
            ->where('o.company_id', $companyId)
            ->whereNull('o.deleted_at')
            ->orderByRaw("case o.status when 'BREACHED' then 0 when 'PENDING' then 1 when 'IN_PROGRESS' then 2 else 3 end")
            ->orderBy('o.due_at')
            ->limit(300)
            ->get([
                'o.uuid', 'o.obligation_def_id', 'o.label', 'o.due_at', 'o.anchor_at', 'o.status',
                'o.responsible_role', 'o.recipient', 'o.evidence_required', 'o.submitted_at',
                'o.regulatory_pack_id', 'o.regulatory_pack_version',
                'i.reference as incident_reference', 'i.uuid as incident_uuid',
                'i.statutory_category',
            ]);

        $obligations = $rows->map(function ($row) use ($now) {
            $dueAt = Carbon::parse($row->due_at);
            $open = in_array($row->status, ['PENDING', 'IN_PROGRESS'], true);

            return [
                'uuid' => $row->uuid,
                'definition' => $row->obligation_def_id,
                'label' => $row->label,
                'due_at' => $dueAt->toIso8601String(),
                'anchor_at' => $row->anchor_at,
                'status' => $open && $dueAt->isPast() ? 'BREACHED' : $row->status,
                'stored_status' => $row->status,
                'hours_remaining' => $open ? (int) round($now->diffInMinutes($dueAt, false) / 60) : null,
                'responsible_role' => $row->responsible_role,
                'recipient' => $row->recipient,
                'evidence_required' => (bool) $row->evidence_required,
                'submitted_at' => $row->submitted_at,
                'pack' => $row->regulatory_pack_id.'@'.$row->regulatory_pack_version,
                'incident' => $row->incident_reference ? [
                    'reference' => $row->incident_reference,
                    'uuid' => $row->incident_uuid,
                    'statutory_category' => $row->statutory_category,
                ] : null,
            ];
        });

        return Inertia::render('Modules/Sheq/Obligations/Index', [
            'obligations' => $obligations->values(),
            'summary' => [
                'breached' => $obligations->where('status', 'BREACHED')->count(),
                'due_24h' => $obligations->where('status', '!=', 'BREACHED')
                    ->filter(fn ($o) => $o['hours_remaining'] !== null && $o['hours_remaining'] <= 24)->count(),
                'open' => $obligations->whereIn('status', ['PENDING', 'IN_PROGRESS'])->count(),
                'submitted' => $obligations->whereIn('status', ['SUBMITTED', 'ACKNOWLEDGED'])->count(),
            ],
            'noCompany' => $companyId === null,
            'can' => ['submit' => (bool) $request->user()?->can('sheq.obligation.submit')],
        ]);
    }

    /**
     * Record that an obligation has been discharged. Evidence of submission is required where the
     * pack says so (SHEQ-REG-4) — "we sent it" without proof is not a defence.
     */
    public function submit(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.obligation.submit'), 403, 'ENTITLEMENT_REQUIRED: sheq.obligation.submit');

        $companyId = $this->requireCompany($request);

        $obligation = DB::table('sheq_statutory_obligations')
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        abort_if($obligation === null, 404);

        $validated = $request->validate([
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($obligation, $companyId, $request, $validated) {
            DB::table('sheq_statutory_obligations')->where('id', $obligation->id)->update([
                'status' => 'SUBMITTED',
                'submitted_at' => now(),
                'submitted_by' => $request->user()?->id,
                'updated_at' => now(),
            ]);

            DB::table('sheq_audit_log')->insert([
                'company_id' => $companyId,
                'actor_id' => $request->user()?->id,
                'actor_type' => 'USER',
                'action' => 'obligation.submitted',
                'subject_type' => 'StatutoryObligation',
                'subject_id' => $obligation->id,
                'before' => json_encode(['status' => $obligation->status]),
                'after' => json_encode([
                    'status' => 'SUBMITTED',
                    'reference' => $validated['reference'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    // Recorded because a late submission is still a breach; hiding it would make
                    // the register lie about the tenant's compliance history.
                    'submitted_late' => Carbon::parse($obligation->due_at)->isPast(),
                ]),
                'reason' => $validated['notes'] ?? null,
            ]);
        });

        return back()->with('success', 'Obligation recorded as submitted.');
    }
}
