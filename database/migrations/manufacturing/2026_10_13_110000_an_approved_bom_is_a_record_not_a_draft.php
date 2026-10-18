<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-002 — the technical definition of a manufactured product, and its bill of materials.
 *
 * The item master is NOT duplicated. fin_stock_items already holds the code, the costing method,
 * the quantity on hand and the inventory accounts, and a second list of items would disagree with
 * it the first time somebody renamed one. mfg_items is an extension row carrying only what
 * engineering needs and finance has no opinion about: the material grade, the drawing, the shelf
 * life, the storage conditions, and what kind of thing it is in a production context.
 *
 * The acceptance criteria drive the BOM design:
 *
 *   "An approved BOM revision cannot be silently overwritten. Historical production orders must
 *    retain the BOM version used when they were released."
 *
 * So an approved BOM is immutable, enforced by database triggers rather than by a service method
 * somebody can forget to call. Changing an approved BOM means creating the next revision and
 * superseding the old one, which leaves both on the record. A production order will reference a
 * bom_id — one specific revision — so a released order keeps the recipe it was costed against
 * even after engineering moves on.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Engineering extension of the stock item ───────────────────────────────────────────
        Schema::create('mfg_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /** The item master. One engineering row per stock item, enforced below. */
            $table->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();

            /*
             * What this thing is in a production context. A raw material is bought and consumed; a
             * subassembly is made and consumed; a by-product comes out of a process nobody ordered
             * it from. The planner treats all three differently, and "is it stock?" cannot tell
             * them apart.
             */
            $table->string('kind', 24); // FINISHED|INTERMEDIATE|SUBASSEMBLY|RAW_MATERIAL|CONSUMABLE|PACKAGING|BY_PRODUCT

            $table->string('category')->nullable();
            $table->text('specification')->nullable();
            $table->foreignId('default_uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();

            // Configurable physical attributes (MFG-002).
            $table->string('material_grade', 64)->nullable();
            $table->string('colour', 64)->nullable();
            $table->string('size', 64)->nullable();
            $table->decimal('weight_kg', 18, 6)->nullable();

            /** length/width/height/uom, kept together because a dimension alone means nothing. */
            $table->jsonb('dimensions')->nullable();

            /** Tenant-defined extras, so a plastics plant and a bakery can both describe an item. */
            $table->jsonb('attributes')->nullable();

            // Shelf life and handling.
            $table->unsignedInteger('shelf_life_days')->nullable();
            $table->text('storage_conditions')->nullable();
            $table->text('handling_instructions')->nullable();

            /*
             * Traceability switches (objective 6). Lot tracking is what makes "which customers got
             * material from supplier batch X" answerable, so it is a property of the item rather
             * than something decided per receipt.
             */
            $table->boolean('is_lot_tracked')->default(false);
            $table->boolean('is_serial_tracked')->default(false);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'kind'], 'mfg_item_kind_idx');
        });
        DB::statement('CREATE UNIQUE INDEX mfg_item_stock_uniq ON mfg_items (company_id, stock_item_id)');

        // ── Engineering change control ────────────────────────────────────────────────────────
        Schema::create('mfg_item_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('mfg_items')->cascadeOnDelete();
            $table->string('version', 24);
            $table->string('status', 16)->default('DRAFT'); // DRAFT|APPROVED|SUPERSEDED|OBSOLETE
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'status'], 'mfg_iver_status_idx');
        });
        DB::statement('CREATE UNIQUE INDEX mfg_iver_uniq ON mfg_item_versions (company_id, item_id, version)');

        Schema::create('mfg_item_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('mfg_items')->cascadeOnDelete();
            $table->foreignId('item_version_id')->nullable()
                ->constrained('mfg_item_versions')->nullOnDelete();

            $table->string('kind', 24); // DRAWING|WORK_INSTRUCTION|SPECIFICATION|CERTIFICATE|OTHER
            $table->string('title');

            /*
             * The file lives in the document module, which already handles storage, encryption,
             * versioning and permissions. Nullable because a drawing may be an external reference
             * (a customer's drawing number) with no file held here at all.
             */
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('external_reference')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'kind'], 'mfg_idoc_kind_idx');
        });

        /*
         * Approved substitutions (MFG-002). A substitution is directional and rated: half a kilo
         * of grade B may stand in for a kilo of grade A, and that ratio is the whole point.
         */
        Schema::create('mfg_item_substitutes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('mfg_items')->cascadeOnDelete();
            $table->foreignId('substitute_stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->decimal('ratio', 18, 6)->default(1);
            $table->text('restriction')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement(
            'CREATE UNIQUE INDEX mfg_isub_uniq ON mfg_item_substitutes '
            .'(company_id, item_id, substitute_stock_item_id)'
        );

        Schema::create('mfg_item_quality_requirements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('mfg_items')->cascadeOnDelete();
            $table->string('standard_code', 64)->nullable();
            $table->string('standard_name');
            $table->string('inspection_stage', 16)->default('FINAL'); // INCOMING|IN_PROCESS|FINAL
            $table->string('inspection_plan_reference')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'item_id'], 'mfg_iqr_item_idx');
        });

        // ── Bill of materials ─────────────────────────────────────────────────────────────────
        Schema::create('mfg_boms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /** The product this recipe builds. */
            $table->foreignId('item_id')->constrained('mfg_items')->cascadeOnDelete();

            $table->unsignedInteger('revision')->default(1);
            $table->string('name')->nullable();
            $table->string('status', 16)->default('DRAFT'); // DRAFT|APPROVED|SUPERSEDED|OBSOLETE

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            /*
             * Yield and batch size. A recipe that makes 100 kg from its component quantities is
             * not the same as one that makes 1 kg a hundred times -- process loss and setup are
             * paid once per batch, so requirements scale by batch rather than linearly.
             */
            $table->decimal('yield_quantity', 18, 6)->default(1);
            $table->foreignId('yield_uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();
            $table->decimal('batch_size', 18, 6)->nullable();

            /** Expected loss across the whole process, on top of per-line scrap. */
            $table->decimal('process_loss_percent', 8, 4)->default(0);

            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('mfg_boms')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'item_id', 'status'], 'mfg_bom_status_idx');
        });
        DB::statement('CREATE UNIQUE INDEX mfg_bom_rev_uniq ON mfg_boms (company_id, item_id, revision)');

        /*
         * One default BOM per product, among the revisions that are still live. Partial, because
         * the previous default stays on the record as SUPERSEDED with is_default still true would
         * otherwise collide with the new one.
         */
        DB::statement(
            'CREATE UNIQUE INDEX mfg_bom_one_default ON mfg_boms (company_id, item_id) '
            ."WHERE is_default IS TRUE AND status = 'APPROVED' AND deleted_at IS NULL"
        );

        Schema::create('mfg_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bom_id')->constrained('mfg_boms')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');

            $table->foreignId('component_stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->decimal('quantity_per', 18, 6);
            $table->foreignId('uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();

            /** Expected scrap on this component specifically — the offcut, not the process loss. */
            $table->decimal('scrap_percent', 8, 4)->default(0);

            /*
             * A phantom assembly is a grouping that is never stocked or built on its own: the
             * planner explodes straight through it to its children. Marked here rather than on the
             * item, because the same subassembly can be phantom in one recipe and stocked in
             * another.
             */
            $table->boolean('is_phantom')->default(false);

            $table->string('line_kind', 16)->default('COMPONENT'); // COMPONENT|PACKAGING|CONSUMABLE|BY_PRODUCT

            /*
             * Lines sharing an alternate_group are one choice, not several requirements: the
             * planner takes whichever is available rather than demanding all of them.
             */
            $table->string('alternate_group', 32)->nullable();
            $table->boolean('is_optional')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'bom_id'], 'mfg_boml_bom_idx');
            $table->index(['company_id', 'component_stock_item_id'], 'mfg_boml_where_used_idx');
        });
        DB::statement('CREATE UNIQUE INDEX mfg_boml_line_uniq ON mfg_bom_lines (bom_id, line_no)');

        Schema::create('mfg_bom_line_alternates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bom_line_id')->constrained('mfg_bom_lines')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->decimal('ratio', 18, 6)->default(1);
            $table->unsignedSmallInteger('priority')->default(1);
            $table->boolean('requires_approval')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();
        });
        DB::statement(
            'CREATE UNIQUE INDEX mfg_bomla_uniq ON mfg_bom_line_alternates (bom_line_id, stock_item_id)'
        );

        /** Who did what to this revision, and why. The revision history the spec asks for. */
        Schema::create('mfg_bom_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bom_id')->constrained('mfg_boms')->cascadeOnDelete();
            $table->string('action', 16); // SUBMITTED|APPROVED|REJECTED|SUPERSEDED|OBSOLETED|REVISED
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acted_at');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'bom_id'], 'mfg_boma_bom_idx');
        });

        $this->applyTenantRls([
            'mfg_items', 'mfg_item_versions', 'mfg_item_documents', 'mfg_item_substitutes',
            'mfg_item_quality_requirements', 'mfg_boms', 'mfg_bom_lines',
            'mfg_bom_line_alternates', 'mfg_bom_approvals',
        ]);

        $this->guardApprovedBoms();
    }

    /**
     * "An approved BOM revision cannot be silently overwritten."
     *
     * In the database, not only in the service. A costing run, an import, a queued job or a future
     * controller that writes a line directly all bypass application code; the one thing none of
     * them can bypass is a trigger. Superseding and obsoleting are still allowed, because those
     * are how a revision is retired — what is refused is editing the recipe itself.
     */
    private function guardApprovedBoms(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            /* A line may not be added to, changed in, or removed from an approved revision. */
            CREATE OR REPLACE FUNCTION mfg_refuse_approved_bom_line() RETURNS trigger AS $$
            DECLARE
                parent_status text;
                parent_id bigint;
            BEGIN
                parent_id := COALESCE(NEW.bom_id, OLD.bom_id);
                SELECT status INTO parent_status FROM mfg_boms WHERE id = parent_id;

                IF parent_status = 'APPROVED' THEN
                    RAISE EXCEPTION
                        'BOM % is approved; create a new revision instead of changing its lines',
                        parent_id;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            /* And the same for a line's alternates, which are part of the recipe. */
            CREATE OR REPLACE FUNCTION mfg_refuse_approved_bom_alternate() RETURNS trigger AS $$
            DECLARE
                parent_status text;
                line_id bigint;
            BEGIN
                line_id := COALESCE(NEW.bom_line_id, OLD.bom_line_id);

                SELECT b.status INTO parent_status
                FROM mfg_bom_lines l
                JOIN mfg_boms b ON b.id = l.bom_id
                WHERE l.id = line_id;

                IF parent_status = 'APPROVED' THEN
                    RAISE EXCEPTION
                        'The BOM behind line % is approved; create a new revision instead',
                        line_id;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            /*
             * The header itself. Retiring a revision is allowed -- that is how engineering moves
             * on -- so status, effective_to, superseded_by_id, is_default and the timestamps stay
             * writable. What is refused is editing the recipe: the product, the yield, the batch
             * size or the process loss of a revision that has already been approved and may
             * already have been costed against and released.
             */
            CREATE OR REPLACE FUNCTION mfg_refuse_approved_bom_edit() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'APPROVED' AND (
                       NEW.item_id              IS DISTINCT FROM OLD.item_id
                    OR NEW.revision             IS DISTINCT FROM OLD.revision
                    OR NEW.yield_quantity       IS DISTINCT FROM OLD.yield_quantity
                    OR NEW.yield_uom_id         IS DISTINCT FROM OLD.yield_uom_id
                    OR NEW.batch_size           IS DISTINCT FROM OLD.batch_size
                    OR NEW.process_loss_percent IS DISTINCT FROM OLD.process_loss_percent
                    OR NEW.effective_from       IS DISTINCT FROM OLD.effective_from
                ) THEN
                    RAISE EXCEPTION
                        'BOM % is approved; its recipe cannot be edited, only superseded',
                        OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER mfg_bom_lines_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON mfg_bom_lines
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_approved_bom_line();

            CREATE TRIGGER mfg_bom_alternates_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON mfg_bom_line_alternates
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_approved_bom_alternate();

            CREATE TRIGGER mfg_boms_immutable
                BEFORE UPDATE ON mfg_boms
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_approved_bom_edit();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_bom_lines_immutable ON mfg_bom_lines');
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_bom_alternates_immutable ON mfg_bom_line_alternates');
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_boms_immutable ON mfg_boms');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_approved_bom_line()');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_approved_bom_alternate()');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_approved_bom_edit()');
        }

        foreach ([
            'mfg_bom_approvals', 'mfg_bom_line_alternates', 'mfg_bom_lines', 'mfg_boms',
            'mfg_item_quality_requirements', 'mfg_item_substitutes', 'mfg_item_documents',
            'mfg_item_versions', 'mfg_items',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
