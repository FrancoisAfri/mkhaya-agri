<?php

use Database\Seeders\WarehouseModuleSeeder;
use Illuminate\Database\Migrations\Migration;

/** Re-runs the idempotent module seeder so the rotation override permission and the batch and expiry cards reach existing installs. */
return new class extends Migration
{
    public function up(): void
    {
        (new WarehouseModuleSeeder)->run();
    }

    public function down(): void
    {
        // Permissions stay: a role may have been granted them by hand.
    }
};
