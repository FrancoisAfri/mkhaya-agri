<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee BCEA 36-month sick-leave cycle anchor. The Basic Conditions of
 * Employment Act grants each employee sick leave equal to the days they'd
 * normally work in 6 weeks, over a rolling 36-month cycle. Nothing in the schema
 * tracked when an employee's 36-month window started, so entitlement could never
 * be granted/reset automatically. This table records each granted window; the
 * scheduled `leave:sick-cycle` command opens a new one (and credits the sick
 * balance) whenever an employee has no open cycle or their current one expires.
 * company_id is written explicitly by the command (tenant context) and used to
 * scope reads — no reliance on RLS defaults for this new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_sick_leave_cycles')) {
            return;
        }

        Schema::create('employee_sick_leave_cycles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->index();      // hr_people.id
            $t->unsignedBigInteger('leave_type_id');             // the sick leave type
            $t->date('cycle_start');
            $t->date('cycle_end');
            $t->decimal('granted_hours', 8, 2)->default(0);
            $t->timestamps();

            $t->index(['company_id', 'employee_id', 'cycle_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sick_leave_cycles');
    }
};
