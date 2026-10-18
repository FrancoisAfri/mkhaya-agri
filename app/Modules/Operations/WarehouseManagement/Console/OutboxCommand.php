<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\Carriers;
use App\Modules\Operations\WarehouseManagement\Services\WebhookDeliveries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delivers warehouse events to webhooks, pulls tracking from carriers whose API offers it, and
 * clears delivered events past their retention (WMS-172). Runs across companies: each outbox row
 * carries its company and every webhook is chosen by it.
 */
class OutboxCommand extends Command
{
    protected $signature = 'wms:outbox {--limit=500}';

    protected $description = 'Deliver warehouse events, pull carrier tracking, prune delivered events';

    public function handle(WebhookDeliveries $deliveries, Carriers $carriers): int
    {
        $done = $deliveries->drain((int) $this->option('limit'));
        $tracked = $carriers->poll();
        $days = (int) config('warehouse.event_retention_days', 90);
        $pruned = DB::table('wms_outbox')->whereNotNull('published_at')->where('published_at', '<', now()->subDays($days))->delete();
        $this->info(sprintf('Delivered %d, retrying %d, dead %d, unsubscribed %d; %d tracking event(s); pruned %d.',
            $done['delivered'], $done['retrying'], $done['dead'], $done['nothing_subscribed'], $tracked, $pruned));

        return self::SUCCESS;
    }
}
