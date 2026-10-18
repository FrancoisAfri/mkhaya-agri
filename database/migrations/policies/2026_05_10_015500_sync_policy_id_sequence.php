<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

            // Keep sequence aligned to the next insertable id.
            DB::statement("SELECT setval(pg_get_serial_sequence('policy','id'), COALESCE((SELECT MAX(id) FROM policy), 0) + 1, false)");
    }

    public function down(): void
    {
        // No-op: sequence sync migration is safe to leave as-is.
    }
};
