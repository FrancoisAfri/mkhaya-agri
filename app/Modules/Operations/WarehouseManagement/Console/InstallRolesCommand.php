<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Support\RolePresets;
use Illuminate\Console\Command;

/** Install the warehouse role templates (WMS-200). --refresh resets presets somebody changed back to the template. */
class InstallRolesCommand extends Command
{
    protected $signature = 'wms:install-roles {--refresh}';

    protected $description = 'Install the warehouse role templates';

    public function handle(): int
    {
        $done = RolePresets::install((bool) $this->option('refresh'));
        $this->info('Created: '.(implode(', ', $done['created']) ?: 'none').'. Refreshed: '.(implode(', ', $done['refreshed']) ?: 'none').'.');

        return self::SUCCESS;
    }
}
