<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Console\Command;

/**
 * Deactivates companies whose free trial has ended and were never converted to a
 * paying subscription. Sets status = suspended (reason "trial expired") so the
 * whole company + all its users are blocked at login (ResolveCompany) until a
 * platform admin reactivates them or they pay.
 */
class ExpireTrials extends Command
{
    protected $signature = 'tenancy:expire-trials';

    protected $description = 'Suspend companies whose free trial has ended';

    public function handle(): int
    {
        $expired = Company::where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get();

        foreach ($expired as $company) {
            $company->update([
                'status' => 'suspended',
                'suspended_at' => now(),
                'suspended_reason' => 'Trial expired',
            ]);
            $this->info("Suspended {$company->name} (trial expired).");
        }

        $this->info("Done. {$expired->count()} company(ies) suspended.");

        return self::SUCCESS;
    }
}
