<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\Models\DueDiligenceCase;
use App\Modules\SupplierManagement\Models\DueDiligenceEddTask;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use Illuminate\Support\Facades\Auth;

/**
 * Builds the enhanced due-diligence pack from what actually triggered it.
 *
 * A fixed checklist is the wrong shape. Ask every high-risk supplier for the same eleven documents
 * and two things happen: the supplier with a bank-account mismatch is asked for its environmental
 * policy, and the one item that mattered gets lost among ten that did not. So each trigger brings
 * its own tasks, and each task carries the trigger that demanded it — the supplier can be told why,
 * and a reviewer can see the demand was a consequence rather than a habit.
 *
 * Tasks are added, never removed. A trigger that has since cleared still explains why the supplier
 * was asked, and quietly deleting the task would erase that.
 */
class EddPack
{
    /**
     * What each trigger demands.
     *
     * Keyed by a fragment of the trigger text the risk engine produces, so the two stay in step
     * without the engine having to know what EDD is.
     *
     * @return array<string, array<int, array{code:string, label:string}>>
     */
    public static function catalogue(): array
    {
        return [
            'bank account holder' => [
                ['code' => 'bank_letter', 'label' => 'Bank-stamped confirmation of account, in the supplier\'s registered name'],
                ['code' => 'banking_change_call', 'label' => 'Call-back to a known contact on a previously held number, confirming the banking details'],
            ],
            'ownership' => [
                ['code' => 'ownership_evidence', 'label' => 'Share certificates or CIPC beneficial-ownership filing'],
                ['code' => 'ubo_declaration', 'label' => 'Signed declaration of ultimate beneficial owners'],
            ],
            'sanctions' => [
                ['code' => 'sanctions_adjudication', 'label' => 'Documented adjudication of every sanctions match by compliance'],
                ['code' => 'legal_opinion', 'label' => 'Legal opinion on whether the engagement may proceed'],
            ],
            'politically exposed' => [
                ['code' => 'pep_source_of_funds', 'label' => 'Source-of-funds and source-of-wealth statement'],
                ['code' => 'pep_approval', 'label' => 'Written senior-management approval to engage a PEP-connected supplier'],
            ],
            'insolvency' => [
                ['code' => 'financial_statements', 'label' => 'Audited or reviewed annual financial statements, most recent two years'],
                ['code' => 'going_concern', 'label' => 'Going-concern statement or business-rescue plan'],
            ],
            'high or critical band' => [
                ['code' => 'trade_references', 'label' => 'Two trade references contactable by phone'],
                ['code' => 'site_visit', 'label' => 'Site visit report confirming the supplier operates from its stated address'],
            ],
            'tier 4' => [
                ['code' => 'security_assessment', 'label' => 'Security and data-protection assessment'],
                ['code' => 'insurance', 'label' => 'Proof of professional indemnity and public liability cover'],
                ['code' => 'subcontractor_disclosure', 'label' => 'Disclosure of subcontractors who would touch the work'],
            ],
            'registry' => [
                ['code' => 'registry_evidence', 'label' => 'Certified copy of the registration certificate'],
            ],
            'tax compliance' => [
                ['code' => 'tax_evidence', 'label' => 'Current tax compliance status confirmation from SARS'],
            ],
        ];
    }

    /**
     * Generate the pack for a case from its newest assessment.
     *
     * @return int how many tasks were added
     */
    public function generate(DueDiligenceCase $case): int
    {
        $snapshot = SupplierRiskScore::latestFor($case->supplier_id);
        $triggers = array_merge($snapshot?->edd_triggers ?? [], $snapshot?->blockers ?? []);

        if ($case->tier >= 4) {
            $triggers[] = 'The engagement is tier 4';
        }

        $added = 0;

        foreach ($triggers as $trigger) {
            foreach ($this->matching($trigger) as $task) {
                $existing = DueDiligenceEddTask::query()
                    ->where('case_id', $case->id)
                    ->where('code', $task['code'])
                    ->first();

                if ($existing) {
                    continue;
                }

                DueDiligenceEddTask::create([
                    'case_id' => $case->id,
                    'supplier_id' => $case->supplier_id,
                    'code' => $task['code'],
                    'label' => $task['label'],
                    // The trigger travels with the task, so the supplier can be told why they are
                    // being asked and a reviewer can see it was a consequence.
                    'why' => $trigger,
                    'is_required' => true,
                    'status' => DueDiligenceEddTask::STATUS_OUTSTANDING,
                ]);

                $added++;
            }
        }

        return $added;
    }

    /** Mark a task provided, or waive it with a reason. */
    public function settle(DueDiligenceEddTask $task, string $status, ?string $note = null): DueDiligenceEddTask
    {
        if ($status === DueDiligenceEddTask::STATUS_WAIVED && trim((string) $note) === '') {
            throw new \InvalidArgumentException('Waiving an enhanced due-diligence item requires a reason.');
        }

        $task->update([
            'status' => $status,
            'response' => $status === DueDiligenceEddTask::STATUS_WAIVED ? $task->response : $note,
            'waiver_reason' => $status === DueDiligenceEddTask::STATUS_WAIVED ? $note : null,
            'completed_by' => Auth::id(),
            'completed_at' => now(),
        ]);

        return $task;
    }

    /** @return array<int, array{code:string, label:string}> */
    private function matching(string $trigger): array
    {
        $needle = mb_strtolower($trigger);
        $tasks = [];

        foreach (self::catalogue() as $fragment => $entries) {
            if (str_contains($needle, $fragment)) {
                $tasks = array_merge($tasks, $entries);
            }
        }

        return $tasks;
    }
}
