<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance-driven absence upload, recorded as reversible batches.
 *
 * Where the ERS pipeline detects absences automatically, this covers the manual
 * route: HR uploads a spreadsheet of days employees did not clock in, reviews
 * exactly what will be deducted, then confirms. Each run is a batch so a
 * mistaken upload can be undone in full rather than unpicked day by day.
 *
 * Tenant-scoped: company_id + FORCE RLS + session-var default.
 */
return new class extends Migration
{
    private array $tables = [
        'leave_absence_upload_batches',
        'leave_absence_upload_batch_items',
    ];

    public function up(): void
    {
        Schema::create('leave_absence_upload_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('leave_type_id')->nullable()->index();
            $table->string('leave_type_name')->nullable();
            $table->decimal('hours_per_day', 8, 2)->default(8);
            $table->string('file_name')->nullable();
            $table->unsignedInteger('total_employees')->default(0);
            $table->decimal('total_days', 12, 2)->default(0);
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

        Schema::create('leave_absence_upload_batch_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('batch_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('employee_name')->nullable();
            $table->string('employee_number')->nullable();
            $table->unsignedBigInteger('leave_type_id')->nullable();
            $table->date('absence_date');
            $table->string('day_of_week', 20)->nullable();
            $table->decimal('hours', 10, 2)->default(0);
            $table->decimal('balance_before', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->unsignedBigInteger('leave_request_id')->nullable()->index();
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
