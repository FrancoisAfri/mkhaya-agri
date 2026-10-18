<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\DerivationService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Recomputes derived figures from the ledger, and reports where the stored ones had drifted
 * (PM-1203, PM-1204).
 *
 * Derivation is deliberately NOT run inside evidence ingestion. A shift's capture is 500 records
 * with a two-second budget (PM-1003); deriving on every append would put the cost of reading on the
 * one path that must stay fast, and a bakkie reaching signal would time out. Instead figures are
 * brought up to date here, nightly, and on demand from the project's own page.
 *
 * `--reconcile` additionally reports any figure that had drifted from what the evidence says.
 * Drift is not normal — it means evidence landed without derivation catching up, or that the rules
 * changed — so it is reported rather than silently corrected.
 */
class DeriveFiguresCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:derive-figures
                            {--company= : Only this company id}
                            {--project= : Only this project uuid}
                            {--reconcile : Report figures that had drifted}';

    protected $description = 'Recompute derived figures from the evidence ledger';

    public function handle(TenantContext $tenants, DerivationService $derivation): int
    {
        $only = $this->option('company');

        if ($only) {
            $this->runFor((int) $only, $tenants, $derivation);

            return self::SUCCESS;
        }

        $this->eachTenant($tenants, fn () => $this->deriveCurrentTenant($derivation));

        return self::SUCCESS;
    }

    private function runFor(int $companyId, TenantContext $tenants, DerivationService $derivation): void
    {
        $tenants->run($companyId, fn () => $this->deriveCurrentTenant($derivation));
    }

    private function deriveCurrentTenant(DerivationService $derivation): void
    {
        $projects = Project::query()
            ->when($this->option('project'), fn ($q, $uuid) => $q->where('uuid', $uuid))
            // Closed projects do not change, and rederiving them every night is work nobody reads.
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->get();

        foreach ($projects as $project) {
            if ($this->option('reconcile')) {
                $result = $derivation->reconcile($project);

                $this->line(sprintf(
                    '%s: %d figure(s) checked, %d diverged.',
                    $project->code, $result['checked'], count($result['diverged'])
                ));

                foreach (array_slice($result['diverged'], 0, 10) as $row) {
                    $this->warn(sprintf(
                        '  %s %s: was %s, now %s',
                        $row['task'], $row['metric'], var_export($row['was'], true), var_export($row['now'], true)
                    ));
                }

                continue;
            }

            $result = $derivation->deriveProject($project);

            $this->line("{$project->code}: {$result['figures']} figure(s) across {$result['tasks']} task(s).");
        }
    }
}
