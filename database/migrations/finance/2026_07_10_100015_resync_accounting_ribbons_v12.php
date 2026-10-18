<?php

use Illuminate\Database\Migrations\Migration;

/** Re-runs accounting registration so the Phase-5 ribbons (Budgets, Tax, Inventory, Expenses) land. */
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
