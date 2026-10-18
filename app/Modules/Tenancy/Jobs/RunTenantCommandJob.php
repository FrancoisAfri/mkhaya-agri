<?php

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Runs one artisan command for ONE company. Heavy per-tenant crons dispatch one
 * of these per active/trial company (via TenantContext::dispatchEachCompany) so
 * the work runs in parallel across queue workers and scales to thousands of
 * tenants — instead of one cron process looping every company in sequence. The
 * command is invoked with `--company=<id>`, and each such command runs its
 * per-company body inside that tenant's context (RLS-scoped).
 */
class RunTenantCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $companyId,
        public string $command,
        public array $options = [],
    ) {}

    public function handle(TenantContext $tenant): void
    {
        try {
            $tenant->run($this->companyId, function () {
                Artisan::call($this->command, $this->options + ['--company' => $this->companyId]);
            });
        } catch (\Throwable $e) {
            Log::warning('tenant cron job failed', [
                'command' => $this->command, 'company_id' => $this->companyId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
