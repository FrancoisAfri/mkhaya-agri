<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable, ordered payroll approval steps (per company) — the same pattern as
 * Fleet booking approvals. Each step routes to a role (anyone with that role) or a
 * specific employee. When a run is submitted, these steps drive the approval chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_approval_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->unsignedInteger('step_number');
            $table->string('name', 120);
            $table->string('approver_type', 20)->default('role'); // role | employee
            $table->string('role_name', 80)->nullable();
            $table->foreignId('employee_id')->nullable(); // users.id when approver_type = employee
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'step_number']);
        });

        // Track which configured step an in-flight approval is currently on.
        if (! Schema::hasColumn('payroll_approvals', 'current_step')) {
            Schema::table('payroll_approvals', function (Blueprint $table) {
                $table->unsignedInteger('current_step')->nullable()->after('current_level');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_approval_configs');
        if (Schema::hasColumn('payroll_approvals', 'current_step')) {
            Schema::table('payroll_approvals', fn (Blueprint $t) => $t->dropColumn('current_step'));
        }
    }
};
