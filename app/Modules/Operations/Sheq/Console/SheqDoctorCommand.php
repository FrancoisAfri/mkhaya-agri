<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Regulatory\SheqReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Whether this deployment of SHEQ is usable (REG-FIX-2).
 *
 * Exits non-zero when something is a blocker, so a deployment pipeline can gate on it. That is the
 * point: the failure this exists to catch is a module that installs clean, migrates clean, shows
 * every screen and refuses the first real incident report — and the only person who finds out is a
 * safety officer typing up an injury.
 *
 * Warnings do not fail the command. An empty induction library is a thin experience, not a broken
 * installation, and a check that fails a pipeline over it is a check somebody disables.
 */
class SheqDoctorCommand extends Command
{
    protected $signature = 'sheq:doctor
                            {--company= : Also check one company\'s own configuration}
                            {--all-companies : Check every company that has the module}';

    protected $description = 'Report whether SHEQ is installed and configured well enough to use';

    public function handle(SheqReadiness $readiness): int
    {
        $failed = false;

        $report = function (?int $companyId) use ($readiness, &$failed): void {
            $result = $readiness->check($companyId);

            $this->newLine();
            $this->components->info($companyId === null
                ? 'Installation'
                : 'Company '.$companyId.' — '.(DB::table('companies')
                    ->where('id', $companyId)->value('name') ?? 'unnamed'));

            foreach ($result['checks'] as $check) {
                $state = $check['ok'] ? '<fg=green>ok</>'
                    : ($check['blocking'] ? '<fg=red>BLOCKED</>' : '<fg=yellow>warning</>');

                $this->components->twoColumnDetail($check['label'], $state);

                if (! $check['ok']) {
                    $this->line('    '.$check['detail']);
                    $this->line('    <fg=cyan>Fix:</> '.$check['fix']);
                }
            }

            if ($result['blockers'] > 0) {
                $failed = true;
            }
        };

        $report(null);

        if ($this->option('company')) {
            $report((int) $this->option('company'));
        } elseif ($this->option('all-companies')) {
            $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

            $companies = $moduleId === null ? collect() : DB::table('security_module_access')
                ->join('users', 'users.id', '=', 'security_module_access.user_id')
                ->where('security_module_access.module_id', $moduleId)
                ->where('security_module_access.active', true)
                ->whereNotNull('users.company_id')
                ->distinct()->pluck('users.company_id');

            foreach ($companies as $companyId) {
                $report((int) $companyId);
            }
        }

        $this->newLine();

        if ($failed) {
            $this->components->error('SHEQ is not ready to use. The blockers above each refuse a '
                .'core workflow rather than merely leaving something unconfigured.');

            return self::FAILURE;
        }

        $this->components->info('SHEQ is ready to use.');

        return self::SUCCESS;
    }
}
