<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Re-runs accounting module registration so the "Debtors Manager" ribbon lands
 * on already-registered environments. Idempotent; prunes stale ribbons.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\AccountingModuleSeeder())->run();
    }

    public function down(): void
    {
        //
    }
};
