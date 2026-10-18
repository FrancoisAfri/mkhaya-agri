<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Ai\EvaluationHarness;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Runs §7.4's release gate and exits non-zero when an agent fails it.
 *
 * The non-zero exit is the useful part: it puts the gate in CI, where a regression stops a deploy,
 * rather than in a dashboard nobody opens until after the complaint.
 */
class EvaluateAiCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:ai:evaluate
                            {--company= : Restrict to one company id}
                            {--agent= : Restrict to one agent}
                            {--days=90 : Window to score over}
                            {--strict : Treat INSUFFICIENT_DATA as a failure too}';

    protected $description = 'Score the SHEQ AI agents against the §7.4 release gate';

    public function handle(TenantContext $tenants, EvaluationHarness $harness): int
    {
        $days = max(1, (int) $this->option('days'));
        $only = $this->option('company') ? (int) $this->option('company') : null;
        $agentFilter = $this->option('agent');
        $failed = 0;
        $thin = 0;

        $evaluate = function (int $companyId) use ($harness, $days, $agentFilter, &$failed, &$thin): void {
            $agents = $agentFilter !== null ? [$agentFilter] : $harness->agents($companyId);

            foreach ($agents as $agent) {
                $result = $harness->gate($companyId, $agent, $days);
                $m = $result['metrics'];

                if ($m['sample'] === 0) {
                    continue;
                }

                $this->newLine();
                $this->components->twoColumnDetail(
                    "<options=bold>{$agent}</> (company {$companyId})",
                    match ($result['verdict']) {
                        'PASS' => '<fg=green>PASS</>',
                        'FAIL' => '<fg=red>FAIL</>',
                        default => '<fg=yellow>INSUFFICIENT DATA</>',
                    },
                );

                $this->components->twoColumnDetail('  drafts / reviewed',
                    "{$m['sample']} / {$m['decided']}");
                $this->components->twoColumnDetail('  hallucination rate',
                    sprintf('%.2f%% (ceiling %.0f%%)',
                        $m['hallucination_rate'] * 100, EvaluationHarness::MAX_HALLUCINATION_RATE * 100));
                $this->components->twoColumnDetail('  citation precision',
                    sprintf('%.2f%% (floor %.0f%%)',
                        $m['citation_precision'] * 100, EvaluationHarness::MIN_CITATION_PRECISION * 100));
                $this->components->twoColumnDetail('  accepted / edited / rejected',
                    sprintf('%.0f%% / %.0f%% / %.0f%%',
                        $m['acceptance_rate'] * 100, $m['edit_rate'] * 100, $m['rejection_rate'] * 100));

                foreach ($result['reasons'] as $reason) {
                    $this->line("    <fg=yellow>→</> {$reason}");
                }

                $result['verdict'] === 'FAIL' ? $failed++ : null;
                $result['verdict'] === 'INSUFFICIENT_DATA' ? $thin++ : null;
            }
        };

        $only !== null
            ? $tenants->run($only, fn () => $evaluate($only))
            : $this->eachTenant($tenants, fn ($id) => $id === null ? null : $evaluate((int) $id));

        $this->newLine();

        if ($failed > 0) {
            $this->components->error("{$failed} agent(s) failed the §7.4 gate.");

            return self::FAILURE;
        }

        if ($thin > 0 && $this->option('strict')) {
            $this->components->error("{$thin} agent(s) have too little review data to judge.");

            return self::FAILURE;
        }

        $this->components->info('All scored agents are within §7.4 thresholds.');

        return self::SUCCESS;
    }
}
