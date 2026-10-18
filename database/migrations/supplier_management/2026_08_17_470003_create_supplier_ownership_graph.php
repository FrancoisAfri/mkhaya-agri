<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: ownership as a graph, because that is what it is.
 *
 * Today a supplier's ownership is a single `parent_id` — one pointer to one company. That cannot
 * express the thing due diligence actually needs to know: ABC Holdings is 60% owned by a person and
 * 40% by XYZ Investments, which is itself 75% owned by somebody else. The person at the end of that
 * chain is the one you are really trading with, and a parent pointer cannot reach them.
 *
 * Two node kinds and one edge table:
 *
 *  supplier_entities   companies in the chain that are not themselves suppliers, plus a row for the
 *                      supplier itself so the graph has a root of the same shape as everything else
 *  supplier_persons    the natural persons (already created in Phase 1)
 *  supplier_ownerships an edge: this holder owns this percentage of this subject, as at this date,
 *                      according to this source
 *
 * Edges are dated and sourced rather than current-only. "Who owned this supplier when the contract
 * was awarded" is a question that gets asked after something has gone wrong, and a table holding
 * only today's position cannot answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Companies appearing in an ownership chain. Some are suppliers in their own right (linked
        // through supplier_ref_id), most are not — a holding company you will never buy from is still
        // part of the answer to who owns the one you do.
        Schema::create('supplier_entities', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            // The supplier whose graph this node belongs to. Kept so a graph can be loaded, and
            // deleted, as one thing.
            $t->unsignedBigInteger('supplier_id')->index();

            $t->string('name', 250);
            $t->string('normalised_name', 250)->nullable()->index();
            $t->string('registration_number', 60)->nullable()->index();
            $t->char('country', 2)->nullable();
            $t->string('entity_type', 60)->nullable();      // private company | trust | close corporation | …

            // Set when this node is the supplier itself, or another supplier on the same tenant —
            // which is how two suppliers sharing a parent become visible.
            $t->unsignedBigInteger('supplier_ref_id')->nullable()->index();
            $t->boolean('is_root')->default(false);

            $t->string('source', 20)->default('supplier');  // supplier | registry | manual
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'supplier_id'], 'supplier_entities_graph_idx');
        });

        Schema::create('supplier_ownerships', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            // Who holds the stake.
            $t->string('holder_kind', 10);                  // person | entity
            $t->unsignedBigInteger('holder_id');

            // What they hold it in. Always an entity node — the supplier itself has one.
            $t->unsignedBigInteger('subject_entity_id');

            $t->decimal('percentage', 6, 3)->nullable();    // null = a stake of unstated size
            $t->string('ownership_type', 20)->default('shares'); // shares | member | beneficial | control | trustee

            // Dated and sourced: an ownership record with no date is a claim about no particular time.
            $t->date('as_at')->nullable();
            $t->date('ended_at')->nullable();
            $t->string('source', 20)->default('supplier');  // supplier | registry | manual
            $t->text('notes')->nullable();

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'supplier_id'], 'supplier_ownerships_graph_idx');
            $t->index(['subject_entity_id', 'holder_kind', 'holder_id'], 'supplier_ownerships_edge_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['supplier_entities', 'supplier_ownerships'] as $table) {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ownerships');
        Schema::dropIfExists('supplier_entities');
    }
};
