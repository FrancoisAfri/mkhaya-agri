<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Services\CrmSequenceService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends the due step of every active SDR sequence enrollment.
 */
class RunSequences extends Command
{
    use RunsPerTenant;

    protected $signature   = 'crm:run-sequences {--company= : Process only this company} {--sync : Process companies inline instead of fanning out to the queue}';
    protected $description = 'Send due steps of active SDR sequences';

    public function handle(CrmSequenceService $sequences): int
    {
        $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class);

        // Process a company's due sequences inside its own tenant context so
        // sends/enrolments are RLS-scoped and correctly tagged.
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $sequences->processDue());
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $totals = ['sent' => 0, 'completed' => 0];
            $this->eachTenant($tenant, function () use ($sequences, &$totals) {
                $r = $sequences->processDue();
                $totals['sent'] += $r['sent'] ?? 0;
                $totals['completed'] += $r['completed'] ?? 0;
            });
            $this->info("Sequence steps sent: {$totals['sent']}, completed: {$totals['completed']}.");
            Log::info('crm:run-sequences completed', $totals);
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('crm:run-sequences');
        $this->info("Dispatched sequence processing for {$n} company(ies).");
        return self::SUCCESS;
    }
}
