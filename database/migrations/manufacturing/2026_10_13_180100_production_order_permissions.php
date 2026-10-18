<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Creates the MFG-006 production order and shop-floor permissions and grants them to the platform admin roles, and
 * re-syncs the module's ribbons from ManufacturingHubs. Idempotent -- the seeder only adds what is
 * missing and prunes ribbons retired from the hub config.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ManufacturingModuleSeeder)->run();
    }

    public function down(): void
    {
        // Permissions stay: a role may have been granted them by hand.
    }
};
