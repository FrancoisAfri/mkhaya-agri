<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Events\WebhookDispatcher;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publishes SHEQ domain events to subscribers (§8.2, SHEQ-ARC-1).
 *
 *   php artisan sheq:outbox:drain
 *   php artisan sheq:outbox:drain --company=1 --limit=50
 *
 * Frequent, because an integration that learns about a fatality an hour late is not an integration.
 * Per-tenant, because RLS is bound to `app.company_id` and an unscoped sweep would both read and
 * write across tenants.
 *
 * A message with no interested subscriber is still marked published: it was offered to everyone who
 * asked, and leaving it pending would grow the queue forever on tenants with no webhooks.
 */
class DrainOutboxCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:outbox:drain
                            {--company= : Restrict to one company id}
                            {--limit=200 : Maximum messages per company per pass}';

    protected $description = 'Deliver SHEQ domain events to tenant webhooks';

    public function handle(TenantContext $tenants, EventPublisher $publisher,
        WebhookDispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $only = $this->option('company') ? (int) $this->option('company') : null;

        $totals = ['published' => 0, 'delivered' => 0, 'failed' => 0];

        $drain = function (int $companyId) use ($publisher, $dispatcher, $limit, &$totals): void {
            foreach ($publisher->pending($companyId, $limit) as $message) {
                try {
                    $result = $dispatcher->dispatch($companyId, $message);

                    $totals['delivered'] += $result['delivered'];
                    $totals['failed'] += $result['failed'];

                    // Only a genuine delivery failure holds a message back. Nobody subscribing is
                    // not a failure — it is the normal case for most tenants.
                    if ($result['failed'] > 0) {
                        $publisher->markFailed((int) $message->id,
                            "{$result['failed']} subscriber(s) rejected the delivery.");

                        continue;
                    }

                    $publisher->markPublished((int) $message->id);
                    $totals['published']++;
                } catch (Throwable $e) {
                    // Recorded against the message rather than aborting the pass: one poisoned
                    // event must not stop the queue behind it.
                    $publisher->markFailed((int) $message->id, $e->getMessage());
                    $totals['failed']++;
                }
            }
        };

        if ($only !== null) {
            $tenants->run($only, fn () => $drain($only));
        } else {
            $this->eachTenant($tenants, function ($companyId) use ($drain) {
                if ($companyId !== null) {
                    $drain((int) $companyId);
                }
            });
        }

        $this->components->info(sprintf(
            'SHEQ outbox: %d published, %d webhook deliveries, %d failures.',
            $totals['published'], $totals['delivered'], $totals['failed']
        ));

        return self::SUCCESS;
    }
}
