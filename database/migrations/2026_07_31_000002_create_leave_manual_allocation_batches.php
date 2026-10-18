<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual leave allocations, recorded as reversible batches.
 *
 * Manual adjustments previously went straight into the balance ledger with no
 * record of the run that caused them, so a mistaken bulk allocation across a
 * whole department could only be undone employee by employee. Each run is now a
 * batch with a row per affected employee (hours, balance before/after), which is
 * what makes both the confirm-preview and the reverse possible.
 *
 * Tenant-scoped: company_id + FORCE RLS + session-var default.
 */
return new class extends Migration
{
    private array $tables = [
        'leave_manual_allocation_batches',
        'leave_manual_allocation_batch_items',
    ];

    public function up(): void
    {
        Schema::create('leave_manual_allocation_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('scope_type', 30)->default('company_unit');  // single | company_unit
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_name')->nullable();
            $table->unsignedBigInteger('leave_type_id')->nullable()->index();
            $table->string('leave_type_name')->nullable();
            $table->string('action', 10)->default('add');               // add | deduct
            $table->decimal('days', 10, 2)->default(0);                 // per employee
            $table->decimal('hours_per_employee', 10, 2)->default(0);
            $table->text('reason')->nullable();
            $table->unsignedInteger('total_employees')->default(0);
            $table->decimal('total_hours', 12, 2)->default(0);
            $table->string('status', 20)->default('applied')->index();  // applied | reversed
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('performed_by_name')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->string('reversed_by_name')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_manual_allocation_batch_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('batch_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('employee_name')->nullable();
            $table->string('employee_number')->nullable();
            $table->unsignedBigInteger('leave_type_id')->nullable();
            $table->decimal('hours', 12, 2)->default(0);         // signed: + credit, − debit
            $table->decimal('balance_before', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->boolean('is_reversed')->default(false);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
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
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
