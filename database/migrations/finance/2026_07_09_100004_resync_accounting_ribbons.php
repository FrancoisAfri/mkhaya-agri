<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Re-runs the accounting module registration so ribbon changes (splitting
 * "Customers & Suppliers" into separate Customers and Suppliers) land on
 * already-registered environments. Idempotent; prunes stale ribbons.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\AccountingModuleSeeder())->run();
    }

    public function down(): void
    {
        // No-op: ribbon layout is managed by the seeder.
    }
};
