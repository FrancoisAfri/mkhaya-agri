<?php

use Illuminate\Database\Migrations\Migration;

/** Re-runs accounting registration so the Bank Feeds + Analytics ribbons land. */
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
