<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company can now subscribe purely à la carte (no bundle), so companies.plan
 * must allow null. Postgres: drop the NOT NULL constraint directly (avoids the
 * ->change() default-value round-trip).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE companies ALTER COLUMN plan DROP NOT NULL');
        } else {
            Schema::table('companies', fn (Blueprint $t) => $t->string('plan')->nullable()->change());
        }
    }

    public function down(): void
    {
        // Leave nullable — re-adding NOT NULL would fail for à-la-carte companies.
    }
};
