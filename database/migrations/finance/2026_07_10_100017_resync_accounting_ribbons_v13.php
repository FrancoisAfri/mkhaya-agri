<?php

use Illuminate\Database\Migrations\Migration;

/** Re-runs accounting registration so the "Dashboard" ribbon (Phase 6.1) lands. */
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
