<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an activity requires beyond a ticket (CON-26), and what to do when a check cannot run (COM-3).
 *
 * An activity could already name the competencies it takes and whether it needs a permit. CON-26
 * lists seven more kinds of evidence, and every one of them is a real reason work gets stopped on
 * a South African site: a medical for confined space, an induction for the site, an approved risk
 * assessment, a method statement, PPE, a certificate for the lifting equipment.
 *
 * They are columns rather than a requirements table on purpose. Each is a yes/no about the
 * activity with no attributes of its own — a row per requirement would add a join to the hottest
 * query in the module (the gate asks this on every card read) to store a boolean. The two that do
 * carry detail, PPE and equipment, hold a JSON list of codes, the same shape
 * `required_credentials` already uses.
 *
 * Everything defaults to FALSE, including on existing rows. The alternative — inferring a
 * requirement from `is_high_risk` — would be the module deciding on upgrade that every
 * working-at-height activity now needs a method statement nobody has filed, and refusing the
 * crews on Monday morning. A host switches these on per activity when they have the paperwork.
 *
 * The settings column is the other half of COM-3. The engine distinguishes "checked and failed"
 * from "could not check", and what the second one does at a gate is not something a vendor should
 * decide for a mine and an office park alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_activities', function (Blueprint $table) {
            /** A certificate of fitness. Confined space, heights, respirator work. */
            $table->boolean('requires_medical')->default(false)->after('required_credentials');

            /**
             * A site induction specific to this work.
             *
             * Distinct from the general site induction every contractor worker needs: this is the
             * task briefing for high-risk work, and a host that runs one wants it checked.
             */
            $table->boolean('requires_induction')->default(false)->after('requires_medical');

            /** An approved risk assessment in force for the work. */
            $table->boolean('requires_risk_assessment')->default(false)->after('requires_induction');

            /** A safe work procedure or method statement on the contractor's file. */
            $table->boolean('requires_method_statement')->default(false)
                ->after('requires_risk_assessment');

            /**
             * PPE codes, as a JSON list.
             *
             * Reported to whoever is at the gate rather than enforced, because this system knows
             * what the work requires and cannot know what the person is wearing. A refusal would
             * be a guess, and it would be wrong every time somebody was correctly kitted out.
             */
            $table->jsonb('required_ppe')->nullable()->after('requires_method_statement');

            /** Certificates for the plant or equipment the work uses — a crane's load test. */
            $table->boolean('requires_equipment_certification')->default(false)
                ->after('required_ppe');
        });

        Schema::table('sheq_settings', function (Blueprint $table) {
            /**
             * What an unverifiable mandatory check does (COM-3).
             *
             * REVIEW_REQUIRED, DENIED or UNKNOWN. Nullable, and NULL reads as REVIEW_REQUIRED in
             * the engine — left null rather than backfilled so a host who has never opened the
             * setting is visibly on the default rather than appearing to have chosen it.
             */
            $table->string('compliance_unverified_outcome', 20)->nullable()
                ->after('contractor_notification_channels');
        });
    }

    public function down(): void
    {
        Schema::table('sheq_activities', function (Blueprint $table) {
            $table->dropColumn(['requires_medical', 'requires_induction',
                'requires_risk_assessment', 'requires_method_statement', 'required_ppe',
                'requires_equipment_certification']);
        });

        Schema::table('sheq_settings', function (Blueprint $table) {
            $table->dropColumn('compliance_unverified_outcome');
        });
    }
};
