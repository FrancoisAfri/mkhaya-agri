<?php

use Illuminate\Database\Migrations\Migration;

/** Re-runs accounting registration so the "Report Builder" ribbon (Phase 6.3) lands. */
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
