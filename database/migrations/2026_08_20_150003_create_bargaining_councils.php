<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bargaining council levies, which were never calculated.
 *
 * An employer party to a council deducts a levy from every employee in the covered sector and pays
 * it over with its own contribution, on the council's own schedule. None of that existed: the only
 * thing in the codebase called `bargaining_council_violation` is a BCEA overtime-rate check wearing
 * the wrong name, so an employer in a council sector had to remember a manual deduction line every
 * month and reconcile it by hand.
 *
 * Rates are dated like the labour rates are, because a council gazettes new ones and a payslip
 * reprinted for an old period has to be measured against what applied then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bargaining_councils', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null company: the shipped library of South African councils, shared like the tax
            // tables. A tenant's own edit copies the row to itself.
            $table->foreignId('company_id')->nullable()->index();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->string('sector')->nullable();
            $table->string('region')->nullable();
            $table->unsignedBigInteger('beneficiary_id')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('bargaining_council_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->unsignedBigInteger('council_id')->index();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // "fixed" is an amount per period; "percentage" is of basic pay.
            $table->string('basis', 20)->default('fixed');
            $table->decimal('employee_amount', 12, 4)->default(0);
            $table->decimal('employer_amount', 12, 4)->default(0);
            $table->decimal('employee_percent', 6, 4)->default(0);
            $table->decimal('employer_percent', 6, 4)->default(0);
            // Councils cap the percentage-based levies; without this a high earner is over-deducted.
            $table->decimal('employee_ceiling', 12, 2)->nullable();
            $table->decimal('employer_ceiling', 12, 2)->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['council_id', 'effective_from']);
        });

        // Which employees a council covers. Membership is per person, not per company: one
        // employer can have covered and uncovered staff in the same run.
        Schema::create('bargaining_council_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->unsignedBigInteger('council_id')->index();
            $table->unsignedBigInteger('employee_id')->index();   // users.id, as payslips use
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();
            $table->timestamps();

            $table->unique(['council_id', 'employee_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $q = chr(39);
            $session = "NULLIF(current_setting({$q}app.company_id{$q}, true), {$q}{$q})";
            // NULL company_id is the shipped library and has to stay visible to every tenant, the
            // same allowance the shared reference tables use.
            $policy = $session . ' IS NULL OR company_id IS NULL OR company_id = ' . $session . '::bigint';
            foreach (['bargaining_councils', 'bargaining_council_rates', 'bargaining_council_members'] as $t) {
                DB::statement("ALTER TABLE \"{$t}\" ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE \"{$t}\" FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$t}\"");
                DB::statement("CREATE POLICY tenant_isolation ON \"{$t}\" USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bargaining_council_members');
        Schema::dropIfExists('bargaining_council_rates');
        Schema::dropIfExists('bargaining_councils');
    }
};
