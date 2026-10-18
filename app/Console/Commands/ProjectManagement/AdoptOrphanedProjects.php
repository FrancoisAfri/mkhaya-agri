<?php

namespace App\Console\Commands\ProjectManagement;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give a home to Project Management records that were created without a tenant.
 *
 * Row-level security matches `company_id = <session>`, and NULL equals nothing. So a project
 * created by a platform admin who had not picked a company is invisible to every tenant user while
 * still appearing for the admin who made it. It looks fine to exactly one person, and to nobody
 * else does it exist: its members cannot see the job they are on, and its contracts cannot be
 * signed, because the signatories cannot load them.
 *
 * The models now refuse to create such a row (`requiresCompany()`), which stops it happening again
 * but does nothing for the ones already there. This is the repair.
 *
 * It asks rather than guesses. There is no signal in the data that says which tenant an orphan
 * belongs to — the creator is a platform admin with no company of their own, which is the whole
 * reason the row is orphaned — so the company is named on the command line by somebody who knows.
 * A migration that picked one would be a silent decision about whose data this is.
 */
class AdoptOrphanedProjects extends Command
{
    protected $signature = 'pm:adopt-orphans
        {--company= : The company id to adopt them into}
        {--project=* : Limit to these project ids (default: all orphans)}
        {--dry-run : List what would change and write nothing}';

    protected $description = 'Stamp untenanted Project Management records with a company';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $orphans = DB::table('pm_projects')->whereNull('company_id')
            ->when($this->option('project'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get(['id', 'name']);

        if ($orphans->isEmpty()) {
            $this->info('No untenanted projects. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line('Untenanted project(s):');
        foreach ($orphans as $p) {
            $counts = $this->dependants($p->id);
            $this->line(sprintf('  %-4d %-45s %d member(s), %d contract(s)', $p->id, $p->name, $counts['members'], $counts['contracts']));
        }

        $companyId = (int) $this->option('company');

        if ($companyId <= 0) {
            $this->newLine();
            $this->warn('Name the company to adopt them into with --company=<id>. Available:');
            foreach (DB::table('companies')->orderBy('id')->get(['id', 'name']) as $c) {
                $this->line("  {$c->id}  {$c->name}");
            }

            return self::FAILURE;
        }

        $company = DB::table('companies')->where('id', $companyId)->first(['id', 'name']);

        if (! $company) {
            $this->error("No company with id {$companyId}.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(($dry ? 'Would adopt' : 'Adopting') . " into: {$company->name} (#{$company->id})");

        $ids = $orphans->pluck('id')->all();
        $totals = ['projects' => 0, 'members' => 0, 'contracts' => 0];

        $count = function () use ($ids, $companyId, $dry, &$totals) {
            $totals['projects']  = $this->stamp('pm_projects', 'id', $ids, $companyId, $dry);
            $totals['members']   = $this->stamp('pm_project_members', 'project_id', $ids, $companyId, $dry);
            $totals['contracts'] = $this->stamp('pm_contracts', 'project_id', $ids, $companyId, $dry);
        };

        // One transaction for the real run: a project stamped without its members leaves the
        // members invisible, which is the same bug in a smaller shape. A dry run only counts, so
        // it needs no transaction to roll back.
        $dry ? $count() : DB::transaction($count);

        return $this->report($totals, $dry);
    }

    /** @param list<int> $ids */
    private function stamp(string $table, string $key, array $ids, int $companyId, bool $dry): int
    {
        $query = DB::table($table)->whereIn($key, $ids)->whereNull('company_id');

        return $dry ? $query->count() : $query->update(['company_id' => $companyId]);
    }

    /** @return array{members:int, contracts:int} */
    private function dependants(int $projectId): array
    {
        return [
            'members'   => DB::table('pm_project_members')->where('project_id', $projectId)->whereNull('company_id')->count(),
            'contracts' => DB::table('pm_contracts')->where('project_id', $projectId)->whereNull('company_id')->count(),
        ];
    }

    private function report(array $totals, bool $dry): int
    {
        $this->info(sprintf(
            '%s %d project(s), %d member(s), %d contract(s).',
            $dry ? 'Would stamp' : 'Stamped',
            $totals['projects'], $totals['members'], $totals['contracts'],
        ));

        return self::SUCCESS;
    }
}
