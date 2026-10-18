<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Put the record right when time, rather than a person, changed the facts (COM-4).
 *
 * Compliance already recalculated when somebody DID something: attaching a document, deciding
 * one, moving a safety file, granting or revoking an override, approving a site. Those are wired
 * and they work.
 *
 * Nothing recalculated when nobody did anything at all, and that is the commonest case by a wide
 * margin. A public liability policy expires at midnight on the 31st. No row is written, because
 * expiry is not an event — it is a date going past. So `access_status` went on reading COMPLIANT
 * until something unrelated happened to touch that contractor, which for a contractor nobody is
 * actively processing can be months.
 *
 * COM-4 states the example almost in these words: insurance expires, APPROVED becomes BLOCKED.
 * The second half was true and the first was not.
 *
 * What made it hard to see is that the gate was right all along. `ComplianceDecisionService`
 * evaluates live, so a worker arriving with a lapsed policy behind them was refused correctly.
 * Everything else reads the stored status: the register, the dashboard, the contractor's own
 * portal, the notifications, the score. So the host saw COMPLIANT, the contractor was told
 * nothing, and the first anybody knew was a crew being turned away at a boom — with the register
 * on the safety officer's screen insisting they should have been let in.
 *
 * Nightly, early, and before the reminder sweep so that what gets chased is the state of play
 * this morning rather than yesterday's.
 */
class RecalculateComplianceCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:compliance:recalculate
                            {--company= : Restrict to one company id}';

    protected $description = 'Recompute contractor compliance where a date, not a person, changed it (COM-4)';

    public function handle(TenantContext $tenants, ContractorService $contractors,
        SafetyFileWorkflow $files): int
    {
        $changed = 0;
        $checked = 0;

        $this->eachTenant($tenants, function (int $companyId) use (
            $contractors, $files, &$changed, &$checked
        ): void {
            /*
             * The safety files first.
             *
             * A lapsed file is a stored state that something has to move, and it feeds the
             * contractor's status. Recomputing the status before stamping it would read the stale
             * state and write a verdict wrong by exactly one day — and then not revisit it until
             * tomorrow, which is how a lapse stays invisible for the whole of its first day,
             * every time.
             *
             * Site approvals need no equivalent pass: `SiteApprovalService::statusFor` derives
             * EXPIRED from the date each time it is asked, so there is no stored state to
             * correct. Adding a stamping sweep for it would invent a second source of truth for
             * something already computed correctly.
             */
            $files->expireLapsed($companyId);

            $contractorUuids = DB::table('sheq_contractors')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)
                ->pluck('uuid');

            foreach ($contractorUuids as $uuid) {
                $checked++;

                $before = DB::table('sheq_contractors')
                    ->where('company_id', $companyId)->where('uuid', $uuid)
                    ->value('access_status');

                try {
                    /*
                     * `refreshAccessStatus` is the single writer of this column, and it already
                     * audits the change and tells the contractor. Reimplementing any of that here
                     * would be a second opinion about the same question — which is the defect
                     * COM-1 spent a day removing.
                     */
                    $after = $contractors->refreshAccessStatus($companyId, $uuid, [], null);
                } catch (Throwable $e) {
                    /*
                     * One contractor's bad data must not stop the sweep. The alternative is a
                     * nightly job that dies on the third of four hundred and leaves the rest of
                     * the estate reading last month's verdict, with nothing but a log line to say
                     * so.
                     */
                    $this->warn("  company {$companyId}: {$uuid} could not be recomputed — "
                        .$e->getMessage());

                    continue;
                }

                if (($after['status'] ?? null) !== $before) {
                    $changed++;
                    $this->line(sprintf('  company %d: %s %s → %s', $companyId, $uuid,
                        $before ?? 'unset', $after['status'] ?? 'unset'));
                }
            }
        });

        $this->info($changed === 0
            ? "Checked {$checked} contractors; nothing has lapsed since the last run."
            : "Checked {$checked} contractors and corrected {$changed}.");

        return self::SUCCESS;
    }
}
