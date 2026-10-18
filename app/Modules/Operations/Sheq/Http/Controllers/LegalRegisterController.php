<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Maintaining the legal register (SHEQ-LEG-2..4).
 *
 * Note what this controller does *not* expose: any way to set a line's status. Compliance is
 * computed from evidence (SHEQ-LEG-3), so the only writes available here are linking evidence,
 * unlinking it, ruling a clause out of scope with a reason, and acknowledging a clause change. If a
 * "mark as compliant" endpoint ever appears in this file, the register has stopped being evidence.
 */
class LegalRegisterController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly LegalRegisterService $register) {}

    public function linkEvidence(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.compliance.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.compliance.manage');

        $validated = $request->validate([
            'evidence_type' => ['required', Rule::in([
                'DOCUMENT', 'INSPECTION_SCHEDULE', 'COMPETENCY', 'APPOINTMENT',
                'ACTION', 'RISK_ASSESSMENT', 'OBLIGATION',
            ])],
            'evidence_id' => ['required', 'integer', 'min:1'],
            'evidence_label' => ['nullable', 'string', 'max:255'],
        ]);

        $line = $this->register->linkEvidence($companyId, $uuid, $validated, $request->user()?->id);

        $this->audit($companyId, $request, 'legal_register.evidence_linked', $uuid, [
            'evidence_type' => $validated['evidence_type'],
            'evidence_id' => $validated['evidence_id'],
            'status_after' => $line['status'],
        ]);

        return back()->with('success', "Evidence linked. The line's status has been recomputed.");
    }

    public function unlinkEvidence(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.compliance.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.compliance.manage');

        $validated = $request->validate([
            'evidence_type' => ['required', 'string', 'max:40'],
            'evidence_id' => ['required', 'integer', 'min:1'],
        ]);

        $line = $this->register->unlinkEvidence($companyId, $uuid,
            $validated['evidence_type'], (int) $validated['evidence_id']);

        $this->audit($companyId, $request, 'legal_register.evidence_unlinked', $uuid, [
            'evidence_type' => $validated['evidence_type'],
            'evidence_id' => $validated['evidence_id'],
            'status_after' => $line['status'],
        ]);

        return back()->with('success', "Evidence unlinked. The line's status has been recomputed.");
    }

    /** Ruling a clause out of scope is a decision with a name against it, not a blank cell. */
    public function markNotApplicable(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.compliance.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.compliance.manage');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->register->markNotApplicable($companyId, $uuid, $validated['reason']);

        $this->audit($companyId, $request, 'legal_register.ruled_out_of_scope', $uuid, [
            'reason' => $validated['reason'],
        ]);

        return back()->with('success', 'Clause ruled out of scope, with the reason on record.');
    }

    public function acknowledgeChange(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.compliance.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.compliance.manage');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $line = $this->register->acknowledgeChange($companyId, $uuid,
            $validated['notes'] ?? null, $request->user()?->id);

        $this->audit($companyId, $request, 'legal_register.change_acknowledged', $uuid, [
            'notes' => $validated['notes'] ?? null,
            'status_after' => $line['status'],
        ]);

        return back()->with('success', 'Clause change acknowledged. The line is back on computed status.');
    }

    private function audit(int $companyId, Request $request, string $action, string $uuid, array $after): void
    {
        $lineId = DB::table('sheq_legal_register_lines')
            ->where('company_id', $companyId)->where('uuid', $uuid)->value('id');

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $request->user()?->id,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'LegalRegisterLine',
            'subject_id' => $lineId,
            'after' => json_encode($after),
        ]);
    }
}
