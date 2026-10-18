<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A maintenance job can be booked without a written description.
 *
 * `fleet_maintenance.description` was `text NOT NULL` with no default, while the form that creates
 * the record validates it as `nullable` and the screen that reads it already types it
 * `string | null` and renders a dash. Every layer above the database had agreed it was optional;
 * only the column had not, so booking a service without typing a description was a 500.
 *
 * Made nullable rather than defaulted to an empty string: an empty description and no description
 * are the same fact, and storing the former would put a value in a column that reads as though
 * somebody had filled it in. The type and category already say what the work is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fleet_maintenance ALTER COLUMN description DROP NOT NULL');
    }

    public function down(): void
    {
        // Anything written while the column was optional has to be given a value before the
        // constraint can go back on, or this fails on the rows the change made possible.
        DB::statement("UPDATE fleet_maintenance SET description = '' WHERE description IS NULL");
        DB::statement('ALTER TABLE fleet_maintenance ALTER COLUMN description SET NOT NULL');
    }
};
