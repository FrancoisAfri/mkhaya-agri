<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Job Cards / workshop management.
 *
 * A job card is the workshop's record of work carried out on a vehicle: what was
 * wrong, which service types apply, which parts were consumed, who signed it off
 * at each approval step, and the mechanic's feedback on completion. Replaces the
 * legacy intranet's job card subsystem.
 *
 * Every table is tenant-scoped: company_id + FORCE RLS + session-var default.
 */
return new class extends Migration
{
    /** Tables created here, in creation order (children last). */
    private array $tables = [
        'fleet_job_card_settings',
        'fleet_job_card_service_types',
        'fleet_job_card_part_categories',
        'fleet_job_card_catalog_parts',
        'fleet_job_card_approval_steps',
        'fleet_job_cards',
        'fleet_job_card_service_type_lines',
        'fleet_job_card_parts',
        'fleet_job_card_approvals',
        'fleet_job_card_notes',
        'fleet_job_card_images',
        'fleet_job_card_documents',
        'fleet_job_card_history',
    ];

    public function up(): void
    {
        // ── Configuration ─────────────────────────────────────────────────────
        Schema::create('fleet_job_card_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('card_number_prefix', 20)->default('JC');
            $table->unsignedInteger('next_card_number')->default(1);
            $table->boolean('require_approval')->default(true);
            $table->boolean('require_mechanic_feedback')->default(true);
            $table->boolean('allow_parts_without_stock')->default(true);
            $table->boolean('notify_on_assignment')->default(true);
            $table->boolean('notify_on_completion')->default(true);
            $table->decimal('default_labour_rate', 10, 2)->default(0);
            $table->timestamps();
        });

        // ── Service type catalogue ────────────────────────────────────────────
        Schema::create('fleet_job_card_service_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->text('description')->nullable();
            $table->decimal('standard_hours', 8, 2)->nullable();
            $table->decimal('standard_cost', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── Parts catalogue ───────────────────────────────────────────────────
        Schema::create('fleet_job_card_part_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fleet_job_card_catalog_parts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('name');
            $table->string('part_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 30)->nullable();          // each | litre | set
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── Approval process flow (step definitions) ──────────────────────────
        Schema::create('fleet_job_card_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('approver_type', 30)->default('user');  // user | role | line_manager
            $table->unsignedBigInteger('approver_user_id')->nullable();
            $table->string('approver_role', 100)->nullable();
            $table->decimal('min_amount', 12, 2)->nullable();      // step only applies above this cost
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── The job card ──────────────────────────────────────────────────────
        Schema::create('fleet_job_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('card_number', 60)->nullable()->index();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->unsignedBigInteger('maintenance_id')->nullable()->index(); // optional link to a scheduled service
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('mechanic_id')->nullable()->index();    // hr_people.id
            $table->unsignedBigInteger('supplier_id')->nullable();             // external workshop
            $table->string('title')->nullable();
            $table->text('fault_description')->nullable();
            $table->text('work_requested')->nullable();
            $table->text('work_performed')->nullable();
            $table->string('priority', 20)->default('normal');   // low|normal|high|emergency
            /*
             * draft -> pending_approval -> approved -> in_progress -> awaiting_feedback
             *       -> completed -> closed;  rejected / cancelled are terminal.
             */
            $table->string('status', 30)->default('draft')->index();
            $table->decimal('odometer', 12, 1)->nullable();
            $table->date('opened_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->decimal('labour_hours', 8, 2)->default(0);
            $table->decimal('labour_rate', 10, 2)->default(0);
            $table->decimal('labour_cost', 12, 2)->default(0);
            $table->decimal('parts_cost', 12, 2)->default(0);
            $table->decimal('other_cost', 12, 2)->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->string('invoice_number', 100)->nullable();
            $table->text('mechanic_feedback')->nullable();
            $table->timestamp('mechanic_feedback_at')->nullable();
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── Service types applied to a card ───────────────────────────────────
        Schema::create('fleet_job_card_service_type_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('job_card_id')->index();
            $table->unsignedBigInteger('service_type_id')->nullable()->index();
            $table->string('name')->nullable();      // snapshot, survives catalogue edits
            $table->decimal('hours', 8, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->timestamps();
        });

        // ── Parts consumed on a card ──────────────────────────────────────────
        Schema::create('fleet_job_card_parts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('job_card_id')->index();
            $table->unsignedBigInteger('catalog_part_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name');                  // snapshot
            $table->string('part_number', 100)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── Per-card approval records ─────────────────────────────────────────
        Schema::create('fleet_job_card_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('job_card_id')->index();
            $table->unsignedBigInteger('step_id')->nullable()->index();
            $table->string('step_name')->nullable();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->unsignedBigInteger('approver_user_id')->nullable()->index();
            $table->string('status', 20)->default('pending');   // pending|approved|rejected|skipped
            $table->text('comment')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();
        });

        // ── Notes / images / documents ────────────────────────────────────────
        Schema::create('fleet_job_card_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('job_card_id')->index();
            $table->string('note_type', 30)->default('general'); // general|cancellation|mechanic
            $table->text('body');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fleet_job_card_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('job_card_id')->index();
            $table->string('caption')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->binary('file_data')->nullable();   // stored in DB, matching fleet_file_storage_to_db
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fleet_job_card_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('job_card_id')->index();
            $table->string('title')->nullable();
            $table->string('document_type', 60)->nullable();  // quote|invoice|warranty|other
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->binary('file_data')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── History / audit trail ─────────────────────────────────────────────
        Schema::create('fleet_job_card_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('job_card_id')->index();
            $table->string('action', 60);                  // created|status_changed|part_added|approved|…
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamps();
        });

        $this->applyTenantIsolation();
    }

    /**
     * company_id defaults from the app.company_id session var and FORCE RLS keeps
     * one tenant's workshop data out of another's, matching the rest of the schema.
     */
    private function applyTenantIsolation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $q = chr(39);
        $session = "NULLIF(current_setting({$q}app.company_id{$q}, true), {$q}{$q})";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE \"{$table}\" FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$table}\"");
            DB::statement("CREATE POLICY tenant_isolation ON \"{$table}\" USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
