<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `hr_people.status` was nullable with no default, and every reader in the system asks
 * `where('status', 1)`.
 *
 * Nothing ever wrote a NULL on purpose — it is simply what a row gets when the code that
 * created it did not mention the column, which is most of the paths that create one. The
 * effect was that an employee could be fully set up, appear in the employee list, and
 * still be missing from every dropdown that offers employees to choose from: one tenant
 * had six people on file and exactly one of them selectable. The symptom reads as "the
 * dropdowns are empty", and it is the same cause in roughly two hundred places, so it is
 * fixed here rather than there.
 *
 * `1` is active and `0` is not; nothing in the codebase writes any other value, and
 * terminations are not recorded on this column. An employee record that exists therefore
 * describes an active employee until somebody deactivates them, which is what the default
 * now says.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hr_people ALTER COLUMN status SET DEFAULT 1');

        // Every row that only ever lacked the column, not the intent. There is not a
        // single explicit 0 in the data, so this resurrects nobody.
        DB::table('hr_people')->whereNull('status')->update(['status' => 1]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE hr_people ALTER COLUMN status DROP DEFAULT');
    }
};
