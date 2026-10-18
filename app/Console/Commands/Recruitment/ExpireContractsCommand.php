<?php

namespace App\Console\Commands\Recruitment;

use App\Modules\HR\Contracts\Models\Contract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireContractsCommand extends Command
{
    protected $signature   = 'recruitment:expire-contracts {--dry-run : List contracts without making changes}';
    protected $description = 'Mark unsigned contracts that have passed their expiry date as Expired';

    public function handle(): int
    {
        // Per company (RLS-scoped) so the sweep never crosses tenants; per-model
        // saves so the audit trail fires.
        $total = 0;
        app(\App\Modules\Tenancy\Services\TenantContext::class)->eachCompany(function () use (&$total) {
            $query = Contract::whereIn('status', ['sent_for_signing', 'approved', 'pending_approval'])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->whereNull('fully_signed_at');

            if ($this->option('dry-run')) {
                $total += $query->count();
                return;
            }

            DB::transaction(function () use ($query, &$total) {
                $query->lockForUpdate()->get()->each(function (Contract $contract) use (&$total) {
                    $contract->status = 'expired';
                    $contract->save();
                    $total++;
                });
            });
        });

        $this->info($this->option('dry-run')
            ? "Dry-run: {$total} contract(s) would be expired."
            : "Expired {$total} contract(s).");

        return self::SUCCESS;
    }
}
