<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Models\OutboxMessage;
use App\Modules\Operations\ProjectManagement\Models\Webhook;
use App\Modules\Operations\ProjectManagement\Services\WebhookDispatcher;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Delivers the transactional outbox (PM-ADR-008, EPIC-070).
 *
 * The outbox guarantees a message exists if and only if the change that produced it committed. This
 * command is the other half: it gets the message out, backs off when the receiver is unhappy, and
 * parks it as a dead letter rather than retrying forever.
 *
 * Per-tenant, because a webhook belongs to a company and a message drained outside a tenant context
 * would be delivered to whichever subscriptions happened to be visible — which, with the RLS
 * session variable unset, is all of them.
 */
class DrainOutboxCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:drain-outbox
                            {--company= : Only this company id}
                            {--limit=200 : Messages per company per run}';

    protected $description = 'Deliver pending Project Management domain events to subscribed webhooks';

    public function handle(TenantContext $tenants, WebhookDispatcher $dispatcher): int
    {
        $totals = ['delivered' => 0, 'retried' => 0, 'dead' => 0, 'skipped' => 0];

        $run = function () use ($dispatcher, &$totals) {
            // Nothing subscribed: mark messages delivered rather than accumulating them forever.
            // The ledger is the record of what happened; the outbox is a delivery queue, and a
            // queue with no consumer is just a table that grows.
            $hasSubscribers = Webhook::active()->exists();

            OutboxMessage::pending()
                ->limit((int) $this->option('limit'))
                ->get()
                ->each(function (OutboxMessage $message) use ($dispatcher, $hasSubscribers, &$totals) {
                    if (! $hasSubscribers) {
                        $message->forceFill(['delivered_at' => now()])->save();
                        $totals['skipped']++;

                        return;
                    }

                    $result = $dispatcher->deliver($message);

                    if ($result['failed'] === 0) {
                        $message->forceFill(['delivered_at' => now()])->save();
                        $totals['delivered']++;

                        return;
                    }

                    $attempts = $message->attempts + 1;

                    if ($attempts >= OutboxMessage::MAX_ATTEMPTS) {
                        // Parked, not dropped: an operator can see exactly what never got out.
                        $message->forceFill([
                            'attempts'   => $attempts,
                            'dead_at'    => now(),
                            'last_error' => "Gave up after {$attempts} attempts.",
                        ])->save();
                        $totals['dead']++;

                        return;
                    }

                    $message->forceFill([
                        'attempts'     => $attempts,
                        'available_at' => now()->addSeconds($message->backoffSeconds()),
                        'last_error'   => "{$result['failed']} subscription(s) failed.",
                    ])->save();
                    $totals['retried']++;
                });
        };

        $this->option('company')
            ? $tenants->run((int) $this->option('company'), $run)
            : $this->eachTenant($tenants, $run);

        $this->info(sprintf(
            '%d delivered, %d retrying, %d dead, %d with no subscriber.',
            $totals['delivered'], $totals['retried'], $totals['dead'], $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
