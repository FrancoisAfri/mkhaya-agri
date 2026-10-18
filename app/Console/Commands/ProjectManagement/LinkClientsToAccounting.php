<?php

namespace App\Console\Commands\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\ProjectPartyResolver;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Which projects can be billed into the ledger, and which cannot yet.
 *
 * Before a project invoice is allowed to become a debtor, somebody has to know that every project's
 * client resolves to the right accounting customer. Discovering that one in forty does not — at the
 * moment an invoice posts to the wrong debtor — is a credit note and a telephone call to a client
 * about money they do not owe.
 *
 * So this reports first and changes nothing. `--apply` creates the missing customers from what PM
 * already knows; the ambiguous ones are never created, because two customers with the same name is
 * a duplicate somebody has to merge and guessing between them attaches a project's whole billing
 * history to a coin toss.
 */
class LinkClientsToAccounting extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:link-clients-to-accounting
        {--company= : Only this company}
        {--apply : Create the missing accounting customers (otherwise report only)}';

    protected $description = 'Report — and optionally create — the accounting customer behind each project client';

    public function handle(TenantContext $tenants, ProjectPartyResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $totals = ['projects' => 0, 'linked' => 0, 'created' => 0, 'blocked' => 0];
        $blocked = [];

        $this->eachTenant($tenants, function () use ($apply, $resolver, &$totals, &$blocked) {
            Project::query()->with('clientRef')->orderBy('id')->chunkById(200, function ($projects) use ($apply, $resolver, &$totals, &$blocked) {
                foreach ($projects as $project) {
                    $totals['projects']++;

                    if ($reason = $resolver->blockedReason($project)) {
                        $totals['blocked']++;
                        $blocked[] = [$project->code, $project->name, $reason];

                        continue;
                    }

                    if ($resolver->resolve($project)) {
                        $totals['linked']++;

                        continue;
                    }

                    if (! $apply) {
                        $totals['blocked']++;
                        $blocked[] = [
                            $project->code,
                            $project->name,
                            __('project_management.billing.no_party', [
                                'client' => $project->clientRef?->display_name ?: '—',
                            ]),
                        ];

                        continue;
                    }

                    if ($resolver->resolveOrCreate($project)) {
                        $totals['created']++;
                    } else {
                        $totals['blocked']++;
                        $blocked[] = [$project->code, $project->name, __('project_management.billing.no_client')];
                    }
                }
            });
        });

        $this->info(sprintf(
            '%s: %d project(s) — %d already linked, %d created, %d needing attention.',
            $apply ? 'Linked' : 'Dry run',
            $totals['projects'],
            $totals['linked'],
            $totals['created'],
            $totals['blocked'],
        ));

        // Named, not counted. "12 blocked" is not a task list; a project code and a reason is.
        if ($blocked !== []) {
            $this->newLine();
            $this->table(['Project', 'Name', 'Why it cannot be billed yet'], array_slice($blocked, 0, 100));

            if (count($blocked) > 100) {
                $this->line(sprintf('…and %d more.', count($blocked) - 100));
            }
        }

        if (! $apply && $totals['blocked'] > 0) {
            $this->newLine();
            $this->line('Re-run with --apply to create the customers that can be created safely.');
        }

        return self::SUCCESS;
    }
}
