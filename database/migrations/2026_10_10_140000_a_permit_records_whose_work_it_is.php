<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A permit to work records which contractor the work is for (CON-20's tenth factor).
 *
 * The contractor score lists permit violations as a factor and could not count them. A permit
 * recorded the site, the work, the dates and the people on it — identified by HR person, by user
 * or by name — and nothing at all about whose work it was. So a suspended permit could not be
 * attributed, and charging it to whichever contractor happened to have people at that site would
 * have been inference presented as a fact.
 *
 * The people on a permit cannot stand in for it either, and that is worth being clear about,
 * because it looks like it should work. `sheq_permit_workers` holds `hr_person_id` for an
 * employee and otherwise a typed NAME — a contractor's people have no personnel record here, so
 * the overwhelming majority of rows for contractor work are names. Matching a contractor by the
 * names on their permits would be a fuzzy join over free text, and the score it produced would
 * be wrong in a way nobody could audit.
 *
 * So the permit says it. Nullable, because most permits on most sites are for the host's own
 * employees and stamping those with a contractor would be worse than leaving it blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_permits', function (Blueprint $table) {
            /**
             * The contractor whose work this permit covers, where it is a contractor's.
             *
             * `nullOnDelete`, not cascade: a permit is the record of a controlled high-risk job
             * that actually happened, and removing a contractor from the register must not take
             * the evidence of it with them.
             */
            $table->foreignId('contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete()->after('site_id');

            // The score reads this per contractor per month, which is the only query shape.
            $table->index(['company_id', 'contractor_id', 'status'], 'sheq_permit_contractor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sheq_permits', function (Blueprint $table) {
            $table->dropIndex('sheq_permit_contractor_idx');
            $table->dropConstrainedForeignId('contractor_id');
        });
    }
};
