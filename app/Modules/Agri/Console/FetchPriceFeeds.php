<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Services\Hedging;
use App\Modules\Agri\Services\PriceFeeds;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Every evening, after the SAFEX close: fetch each organisation's commodity price feeds that are
 * due (through the integration log, once a day each; a failure backs off and is retried there),
 * then mark every open hedge to market at the latest futures price — also when no feed is set up,
 * so hedges marked against typed-in prices carry the day forward.
 */
class FetchPriceFeeds extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:price-feeds {--company= : Only this organisation}';

    protected $description = 'Fetch Mkhaya Agri commodity price feeds and mark hedges to market';

    public function handle(TenantContext $tenants, PriceFeeds $feeds, Hedging $hedging): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($feeds, $hedging) {
            foreach ($feeds->due($companyId) as $feed) {
                $r = $feeds->run($feed);
                $r['ok'] ? $this->line("{$feed->name}: {$r['items']} price(s).") : $this->error("{$feed->name}: {$r['error']}");
            }
            $this->line("Company {$companyId}: ".$hedging->markAll($companyId).' hedge(s) marked.');
        });

        return self::SUCCESS;
    }
}
