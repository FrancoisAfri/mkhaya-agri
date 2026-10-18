<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No-op: all columns added here were consolidated into the base
        // 2024_04_20_130043_create_policy_table.php migration.
    }

    public function down(): void
    {
        // No-op: see up().
    }
};
