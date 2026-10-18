<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\NotificationSender;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Drains the SHEQ notification queue, one tenant at a time (SHEQ-INC-4/5).
 *
 *   php artisan sheq:notifications:send              # every active tenant
 *   php artisan sheq:notifications:send --company=7
 *
 * Runs per company rather than across the whole table because row-level security is bound to
 * `app.company_id`: a query outside a tenant context sees everything, which is precisely the leak
 * this platform has had to fix repeatedly elsewhere. `TenantContext::eachCompany` sets the session
 * variable per iteration so RLS stays active in the scheduler exactly as it is in a request.
 */
class SendNotificationsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:notifications:send
                            {--company= : Restrict to one company id}
                            {--limit=200 : Maximum dispatches per company per pass}';

    protected $description = 'Send due SHEQ incident notifications';

    public function handle(TenantContext $tenants, NotificationSender $sender): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $only = $this->option('company') ? (int) $this->option('company') : null;

        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        $drain = function (int $companyId) use ($sender, $limit, &$totals): void {
            $result = $sender->drain($companyId, $limit);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            if (array_sum($result) > 0) {
                $this->components->twoColumnDetail(
                    "Company {$companyId}",
                    "{$result['sent']} sent, {$result['failed']} failed, {$result['skipped']} unsupported"
                );
            }
        };

        if ($only !== null) {
            $tenants->run($only, fn () => $drain($only));
        } else {
            // eachCompany yields null on an installation with no companies yet. There is nothing to
            // drain in that case, and casting it to 0 would query a tenant that does not exist.
            $this->eachTenant($tenants, function ($companyId) use ($drain) {
                if ($companyId !== null) {
                    $drain((int) $companyId);
                }
            });
        }

        $this->components->info(
            "SHEQ notifications: {$totals['sent']} sent, {$totals['failed']} failed, ".
            "{$totals['skipped']} on channels this platform cannot yet carry."
        );

        // A failed send is not a failed command — the rows stay queued and the next pass retries.
        return self::SUCCESS;
    }
}
