<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whose action is it (CON-18)?
 *
 * `sheq_actions` records what has to be done, who it is assigned to and where. It has no way to
 * say that the party responsible is a contractor rather than an employee — `assigned_to` is a
 * user id, and a contractor's administrator has no account here.
 *
 * So the self-service portal could show a contractor their documents, their safety file and
 * their people, and then had nothing to put under "outstanding actions" but an empty list.
 * Which is worse than it sounds: a contractor reading "nothing outstanding" takes it as
 * confirmation that they are up to date, and the finding raised against them after last
 * month's inspection goes unanswered until somebody telephones.
 *
 * `source_type`/`source_id` already exist, and an action raised FROM a contractor record could
 * in principle be found through them. That is the wrong relationship: the source is what
 * prompted the action, not who owes it. An inspection of a contractor's scaffolding raises an
 * action sourced to the inspection and owed by the contractor, and conflating the two makes
 * either question unanswerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_actions', function (Blueprint $table) {
            /*
             * Nullable, because most actions belong to the host's own people. A contractor is
             * named only when somebody deliberately says so.
             */
            $table->unsignedBigInteger('contractor_id')->nullable()->after('assigned_to');

            $table->index(['company_id', 'contractor_id', 'status'], 'sheq_act_contractor_idx');
        });

        if (Schema::hasTable('sheq_contractors')) {
            Schema::table('sheq_actions', function (Blueprint $table) {
                // nullOnDelete rather than cascade: removing a contractor from the register must
                // not delete the record of what they were asked to fix.
                $table->foreign('contractor_id')->references('id')->on('sheq_contractors')
                    ->nullOnDelete();
            });
        }

        $this->adoptActionsRaisedAgainstContractors();
    }

    public function down(): void
    {
        Schema::table('sheq_actions', function (Blueprint $table) {
            if (Schema::hasTable('sheq_contractors')) {
                $table->dropForeign(['contractor_id']);
            }

            $table->dropIndex('sheq_act_contractor_idx');
            $table->dropColumn('contractor_id');
        });
    }

    /**
     * Where an action was already raised from a contractor record, say so.
     *
     * Not a guess: `source_type = 'CONTRACTOR'` means somebody raised it from that contractor's
     * page, and the owner is the only reasonable reading. Everything else is left alone —
     * inferring ownership from a site, or from who it is assigned to, would put actions on
     * contractors' portals that nobody meant them to see.
     */
    private function adoptActionsRaisedAgainstContractors(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            UPDATE sheq_actions a
               SET contractor_id = c.id
              FROM sheq_contractors c
             WHERE a.source_type = 'CONTRACTOR'
               AND a.source_id = c.id
               AND a.company_id = c.company_id
               AND a.contractor_id IS NULL
        ");
    }
};
