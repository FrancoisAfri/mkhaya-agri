<?php

namespace App\Console\Commands\Recruitment;

use App\Modules\HR\Offers\Models\Offer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireOffersCommand extends Command
{
    protected $signature   = 'recruitment:expire-offers {--dry-run : List candidates without making changes}';
    protected $description = 'Mark sent offers that have passed their expiry date as Expired';

    public function handle(): int
    {
        // Per company (RLS-scoped) so cross-tenant offers aren't touched in one
        // sweep. Per-model saves (not a bulk update) so the audit trail fires and
        // the still-live portal token/OTP hashes are invalidated with the expiry.
        $total = 0;
        app(\App\Modules\Tenancy\Services\TenantContext::class)->eachCompany(function () use (&$total) {
            $query = Offer::where('status', 'sent')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now());

            if ($this->option('dry-run')) {
                $total += $query->count();
                return;
            }

            DB::transaction(function () use ($query, &$total) {
                $query->lockForUpdate()->get()->each(function (Offer $offer) use (&$total) {
                    $offer->status     = 'expired';
                    $offer->token_hash = null;
                    $offer->otp_hash   = null;
                    $offer->save();
                    $total++;
                });
            });
        });

        $this->info($this->option('dry-run')
            ? "Dry-run: {$total} offer(s) would be expired."
            : "Expired {$total} offer(s).");

        return self::SUCCESS;
    }
}
