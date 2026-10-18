<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Fetch all rows with a null uuid (UUID column type rejects empty string in PostgreSQL)
        $rows = DB::table('hr_people')
            ->whereNull('uuid')
            ->pluck('id');

        foreach ($rows as $id) {
            DB::table('hr_people')
                ->where('id', $id)
                ->update(['uuid' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        // Not reversible — UUIDs are data, not schema changes
    }
};
