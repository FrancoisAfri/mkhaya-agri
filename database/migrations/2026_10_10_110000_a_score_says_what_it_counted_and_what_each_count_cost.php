<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A contractor score that can be argued with (CON-20).
 *
 * The score was five hard-coded weights producing one number. Two problems with that, and the
 * second is worse than the first.
 *
 * CON-20 lists ten factors and five were counted. Document compliance, safety file completeness,
 * near misses, audit findings and competency compliance all bore on whether a contractor was
 * safe to use and none of them touched the figure a procurement manager renewed a contract on.
 *
 * And nothing could explain the number. A contractor told their score is 61 asks why, and the
 * only answer available was to read the source. That is not a pedantic objection: these figures
 * decide who gets work, they get disputed, and a score that cannot show its working gets
 * overruled by whoever argues hardest -- which defeats the entire point of counting rather than
 * rating by hand.
 *
 * So the breakdown is STORED rather than recomputed on demand. A score recomputed when somebody
 * asks is a score that can quietly change its own history: the registers behind it move -- an
 * incident gets reclassified, an action is closed late -- and last March's figure would then
 * explain itself with this March's data. The stored breakdown is what was counted at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_contractor_scores', function (Blueprint $table) {
            /** The five factors CON-20 lists that were never counted. */
            $table->unsignedInteger('near_misses')->default(0)->after('high_potential_incidents');
            $table->unsignedInteger('audit_findings')->default(0)->after('inspection_failures');
            $table->unsignedInteger('document_gaps')->default(0)->after('audit_findings');
            $table->unsignedInteger('safety_file_gaps')->default(0)->after('document_gaps');
            $table->unsignedInteger('competency_gaps')->default(0)->after('safety_file_gaps');

            /**
             * How the number was arrived at: every factor, its count, its weight and what it
             * cost. The explanation CON-20 requires, kept as evidence rather than regenerated.
             */
            $table->jsonb('breakdown')->nullable()->after('score');

            /**
             * The weights in force when this score was computed.
             *
             * Without this, changing a weight silently rewrites history: a host who decides
             * incidents should count double would make every past month's figure unexplainable
             * by its own stored breakdown. Carried so a score always adds up.
             */
            $table->jsonb('weights')->nullable()->after('breakdown');
        });

        Schema::table('sheq_settings', function (Blueprint $table) {
            /**
             * Per-tenant factor weights (CON-20's "configurable factors").
             *
             * NULL means the shipped defaults, so a host who has never opened the screen is
             * visibly on the defaults rather than appearing to have chosen them.
             */
            $table->jsonb('contractor_score_weights')->nullable()
                ->after('compliance_unverified_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('sheq_contractor_scores', function (Blueprint $table) {
            $table->dropColumn(['near_misses', 'audit_findings', 'document_gaps',
                'safety_file_gaps', 'competency_gaps', 'breakdown', 'weights']);
        });

        Schema::table('sheq_settings', function (Blueprint $table) {
            $table->dropColumn('contractor_score_weights');
        });
    }
};
