<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether somebody is an intern or a learner, on both payroll engines.
 *
 * Neither employee record could say it. The bureau's had a start date and an `eti_opt_in` flag that
 * defaulted to true for everybody; the in-house `hr_people.employment_type` is an integer with
 * exactly two values, permanent and temporary, and belongs to employment-equity reporting rather
 * than payroll. So the one category the Employment Tax Incentive exists for — and the one a payroll
 * administrator is asked to watch milestones on — was unrecorded on both sides.
 *
 * A new column rather than a third meaning for the old one. Overloading `employment_type` would have
 * been quicker and would have quietly changed what every existing EE report meant.
 *
 * `eti_start_date` is deliberately separate from the employment start date. They are usually the
 * same, and when they are not it is for real reasons: somebody who turns eighteen in post, or a
 * learnership beginning after probation, starts claiming later than they started working. Inferring
 * one from the other is what produces a twenty-four-month window measured from the wrong day.
 */
return new class extends Migration
{
    /** The payroll-facing vocabulary, shared by both engines so one ledger can serve both. */
    private const CATEGORIES = 'permanent | fixed_term | intern | learnership | casual';

    public function up(): void
    {
        Schema::table('bureau_employees', function (Blueprint $table) {
            $table->string('employment_category', 20)->nullable()->after('occupation')
                ->comment(self::CATEGORIES);
            $table->date('eti_start_date')->nullable()->after('eti_opt_in');

            // The ETI and anniversary screens filter on this; a client with four hundred staff
            // should not be scanned to find its six interns.
            $table->index(['bureau_company_id', 'employment_category'], 'bureau_employees_category_index');
        });

        Schema::table('hr_people', function (Blueprint $table) {
            $table->string('employment_category', 20)->nullable()->comment(self::CATEGORIES);
            // The in-house side had no ETI fields at all — the engine does not compute the
            // incentive, which is the next thing this makes possible.
            $table->boolean('eti_opt_in')->default(false);
            $table->date('eti_start_date')->nullable();

            $table->index('employment_category', 'hr_people_category_index');
        });
    }

    public function down(): void
    {
        Schema::table('bureau_employees', function (Blueprint $table) {
            $table->dropIndex('bureau_employees_category_index');
            $table->dropColumn(['employment_category', 'eti_start_date']);
        });

        Schema::table('hr_people', function (Blueprint $table) {
            $table->dropIndex('hr_people_category_index');
            $table->dropColumn(['employment_category', 'eti_opt_in', 'eti_start_date']);
        });
    }
};
