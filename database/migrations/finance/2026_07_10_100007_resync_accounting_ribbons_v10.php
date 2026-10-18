<?php

use Illuminate\Database\Migrations\Migration;

/** Re-runs accounting module registration so the "Cash Flow Forecast" ribbon lands. */
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
