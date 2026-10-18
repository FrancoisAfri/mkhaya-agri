<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An onboarding person no longer has to have come from recruitment.
 *
 * `hr_persons.candidate_id` and `contract_id` were both NOT NULL, so the only way to exist was to
 * be a candidate who signed a contract. That is the right story for a normal hire and the wrong one
 * for everything else: a bulk import of an existing workforce, a transfer from another system, a
 * person hired outside the ATS.
 *
 * Dropping the constraint alone would produce people with no name, because the name was only ever
 * on the candidate. So the identity moves onto the person: their own columns when they have them,
 * the candidate's when they do not (see HrPerson::fullName()). Existing rows are untouched and keep
 * reading through the relation exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hr_persons ALTER COLUMN candidate_id DROP NOT NULL');
        DB::statement('ALTER TABLE hr_persons ALTER COLUMN contract_id DROP NOT NULL');

        Schema::table('hr_persons', function (Blueprint $table) {
            // Nullable rather than required: a person created from a candidate carries none of
            // these, and backfilling them would duplicate the candidate's record rather than
            // reference it — two copies of a name that can then disagree.
            if (! Schema::hasColumn('hr_persons', 'first_name')) {
                $table->string('first_name')->nullable();
                $table->string('surname')->nullable();
                $table->string('email')->nullable()->index();
                $table->string('phone')->nullable();
                $table->string('id_number')->nullable();
            }

            // Where this person came from, so a bulk-imported row is distinguishable from an ATS
            // hire without inferring it from which foreign keys happen to be null.
            if (! Schema::hasColumn('hr_persons', 'source')) {
                $table->string('source')->nullable()->index();
                $table->unsignedBigInteger('bulk_import_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // The NOT NULLs are deliberately not restored: rows created while they were optional would
        // make the constraint un-appliable, and a migration that cannot roll back cleanly is worse
        // than one that leaves a column nullable.
        Schema::table('hr_persons', function (Blueprint $table) {
            foreach (['first_name', 'surname', 'email', 'phone', 'id_number', 'source', 'bulk_import_id'] as $column) {
                if (Schema::hasColumn('hr_persons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
