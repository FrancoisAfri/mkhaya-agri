<?php

use Illuminate\Database\Migrations\Migration;

/** Re-runs accounting registration so the "Consolidation" ribbon (Phase 6.4) lands. */
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
