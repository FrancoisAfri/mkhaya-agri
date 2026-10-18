<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseHealth;
use Illuminate\Console\Command;

/** For external monitoring (WMS-212): prints each check and exits 1 when any is red. */
class HealthCommand extends Command
{
    protected $signature = 'wms:health {--json}';

    protected $description = 'Warehouse operational health';

    public function handle(WarehouseHealth $health): int
    {
        $checks = $health->checks();
        if ($this->option('json')) {
            $this->line(json_encode($checks));
        } else {
            $this->table(['Check', 'State', 'Detail'], array_map(fn ($c) => [$c['check'], strtoupper($c['state']), $c['detail']], $checks));
        }

        return collect($checks)->contains('state', 'red') ? self::FAILURE : self::SUCCESS;
    }
}
