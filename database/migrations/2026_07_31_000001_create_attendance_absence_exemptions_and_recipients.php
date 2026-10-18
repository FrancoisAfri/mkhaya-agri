<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two lists the ERS absence pipeline needs and did not have:
 *
 *  - exemptions: staff who are never expected to clock in (field workers, execs,
 *    permanently remote staff). Without this they are flagged absent every day
 *    and, with auto-apply on, have leave deducted for working normally.
 *
 *  - report recipients: who receives the daily absent-users report.
 *
 * Both tenant-scoped: company_id + FORCE RLS + session-var default.
 */
return new class extends Migration
{
    private array $tables = [
        'attendance_absence_exemptions',
        'attendance_absence_report_recipients',
    ];

    public function up(): void
    {
        Schema::create('attendance_absence_exemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();   // hr_people.id
            $table->string('reason')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();             // null = open-ended
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_absence_report_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index(); // hr_people.id
            $table->string('email')->nullable();   // for recipients who aren't employees
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
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

        // Whether the daily absent-users report goes out at all.
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->boolean('absent_manager_report_enabled')->default(false);
            $table->string('absent_manager_report_time', 5)->default('10:00');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['absent_manager_report_enabled', 'absent_manager_report_time']);
        });

        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
