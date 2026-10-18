<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Principal contractor, subcontractor, worker (CON-25).
 *
 * The register was flat: every contractor answered directly to the host, and a subcontractor was
 * just another contractor with its own row. That is not how a South African construction site is
 * arranged, and it is not how the law arranges it either — Construction Regulation 7 makes the
 * principal contractor responsible for the subcontractors it appoints, including for taking
 * reasonable steps to ensure they comply.
 *
 * So the question a principal contractor is actually accountable for — "are the firms I appointed
 * in order?" — could not be asked. They had no visibility of their own subcontractors' compliance
 * unless the host sent it to them by email, which means it was out of date before it arrived.
 *
 * A self-referencing parent rather than a separate hierarchy table. A subcontractor IS a
 * contractor: it files the same documents, holds the same safety file, has its own workers and its
 * own site approvals, and it may itself appoint others. A second table would duplicate every one
 * of those relationships, and the first thing anybody would want is to treat the two the same.
 *
 * Two levels is the realistic case and this does not cap it, because the realistic case is not
 * the only one: a principal appoints an electrical subcontractor who appoints a specialist
 * testing firm, and refusing to record that would push it off the system altogether. What it does
 * refuse is a cycle — see the trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_contractors', function (Blueprint $table) {
            /**
             * The contractor that appointed this one, where one did.
             *
             * NULL means they answer directly to the host, which is what every existing row is
             * and must remain: inferring a hierarchy from trade or from site would invent
             * accountability nobody accepted.
             *
             * `nullOnDelete` rather than cascade. Removing a principal must not take its
             * subcontractors' records with it — those firms were on site, they filed documents,
             * and there may be an incident attached to one of them.
             */
            $table->foreignId('parent_contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete()->after('supplier_id');

            /**
             * What the principal is allowed to see about the firms it appointed.
             *
             * A principal contractor is accountable for its subcontractors' compliance, which
             * means it must be able to see the state of it. It does not follow that it should see
             * everything: a subcontractor's medical certificates are their workers' health
             * information, and a competitor's rates or a dispute with the host are nobody's
             * business. Defaults to the compliance summary and nothing else.
             */
            $table->jsonb('subcontractor_visibility')->nullable()->after('parent_contractor_id');

            $table->index(['company_id', 'parent_contractor_id'], 'sheq_contractor_parent_idx');
        });

        /*
         * Refuse a cycle.
         *
         * A contractor that is its own ancestor makes every tree walk in the application a
         * non-terminating loop — the compliance rollup, the portal, the register's hierarchy
         * column. It happens by accident, from a two-step edit nobody thought of as a step: A is
         * given B as its parent, and later somebody gives B the parent A.
         *
         * Enforced in the database rather than in a service, because the register is written from
         * a controller, an importer, a seeder and the supplier bridge, and a guard in one of them
         * is a guard three paths walk past.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION sheq_contractor_no_cycle() RETURNS TRIGGER AS $$
                DECLARE
                    ancestor BIGINT;
                    depth INT := 0;
                BEGIN
                    IF NEW.parent_contractor_id IS NULL THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.parent_contractor_id = NEW.id THEN
                        RAISE EXCEPTION
                            'A contractor cannot be its own principal contractor (CON-25)'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    ancestor := NEW.parent_contractor_id;

                    WHILE ancestor IS NOT NULL LOOP
                        depth := depth + 1;

                        IF ancestor = NEW.id THEN
                            RAISE EXCEPTION
                                'That appointment would make a loop in the contractor chain (CON-25)'
                                USING ERRCODE = 'check_violation';
                        END IF;

                        -- A belt-and-braces stop. If existing data already holds a loop, the walk
                        -- above would never end and the insert would hang rather than fail.
                        IF depth > 32 THEN
                            RAISE EXCEPTION
                                'The contractor chain is too deep to verify (CON-25)'
                                USING ERRCODE = 'check_violation';
                        END IF;

                        SELECT parent_contractor_id INTO ancestor
                        FROM sheq_contractors WHERE id = ancestor;
                    END LOOP;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::statement('CREATE TRIGGER sheq_contractor_no_cycle_trg
                           BEFORE INSERT OR UPDATE OF parent_contractor_id ON sheq_contractors
                           FOR EACH ROW EXECUTE FUNCTION sheq_contractor_no_cycle()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS sheq_contractor_no_cycle_trg ON sheq_contractors');
            DB::statement('DROP FUNCTION IF EXISTS sheq_contractor_no_cycle()');
        }

        Schema::table('sheq_contractors', function (Blueprint $table) {
            $table->dropIndex('sheq_contractor_parent_idx');
            $table->dropConstrainedForeignId('parent_contractor_id');
            $table->dropColumn('subcontractor_visibility');
        });
    }
};
