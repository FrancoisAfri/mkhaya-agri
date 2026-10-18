<?php

namespace App\Modules\SupplierManagement\Console;

use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use Illuminate\Console\Command;

/**
 * Writes the verification-source catalogue into the database.
 *
 * Re-runnable: entries are upserted by code, so this both installs the catalogue on a new
 * installation and refreshes capabilities and access notes on an existing one. Tenant credentials
 * live in a different table and are never touched here.
 */
class InstallVerificationProvidersCommand extends Command
{
    protected $signature = 'suppliers:providers:install';

    protected $description = 'Install or refresh the due-diligence verification provider catalogue';

    public function handle(): int
    {
        $count = ProviderCatalogue::install();

        $this->info("Installed/refreshed {$count} verification provider(s).");
        $this->line('Configure credentials per company at Suppliers → Configure → Data sources.');

        return self::SUCCESS;
    }
}
