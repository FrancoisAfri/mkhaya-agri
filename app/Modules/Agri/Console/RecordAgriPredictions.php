<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Services\AgriPredictions;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Daily (Phase 2 §10.2): write down each organisation's predictions as they stand today — yield,
 * harvest readiness, stock running out, machine breakdown risk — and score the ones whose outcome
 * is now known, so every model's record on the farm's own history stays current.
 */
class RecordAgriPredictions extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:predictions {--company= : Only this organisation}';

    protected $description = 'Record and score Mkhaya Agri predictions for every organisation';

    public function handle(TenantContext $tenants, AgriPredictions $predictions): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($predictions) {
            $made = $predictions->snapshot($companyId);
            $scored = $predictions->score($companyId);
            $this->line("Company {$companyId}: {$made} prediction(s) recorded, {$scored} scored.");
        });

        return self::SUCCESS;
    }
}
