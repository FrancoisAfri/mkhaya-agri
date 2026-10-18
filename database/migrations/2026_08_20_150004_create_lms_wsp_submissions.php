<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The WSP/ATR submission, and what came back from the SETA.
 *
 * Payroll calculates and pays SDL every month. The LMS knew the 30 April deadline existed and
 * warned about it, but nothing recorded whether a plan was actually submitted or what the SETA
 * paid back — so an employer could not answer "how much of last year's levy did we recover?", and
 * the mandatory grant, a fifth of the levy, went unclaimed with nobody noticing.
 *
 * This is the row that joins the two: payroll knows the levy, the LMS knows the training, and the
 * grant is the number that only makes sense with both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_wsp_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->index();
            // The levy year the plan covers, named by the year it ends in: 2027 is April 2026 to
            // March 2027, which is the WSP cycle rather than the tax year.
            $table->unsignedSmallInteger('year')->index();
            $table->string('seta', 30)->nullable();
            $table->string('sdl_number', 30)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('reference')->nullable();
            // What was expected against the levy, and what actually arrived.
            $table->decimal('grant_expected', 14, 2)->default(0);
            $table->decimal('grant_received', 14, 2)->default(0);
            $table->date('grant_received_on')->nullable();
            $table->string('status', 20)->default('draft');   // draft | submitted | approved | rejected | paid
            $table->text('notes')->nullable();
            $table->timestamps();

            // One plan per employer per year — a second is a correction, not another claim.
            $table->unique(['company_id', 'year']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $q = chr(39);
            $session = "NULLIF(current_setting({$q}app.company_id{$q}, true), {$q}{$q})";
            $policy = $session . ' IS NULL OR company_id = ' . $session . '::bigint';
            DB::statement('ALTER TABLE "lms_wsp_submissions" ALTER COLUMN company_id SET DEFAULT ' . $session . '::bigint');
            DB::statement('ALTER TABLE "lms_wsp_submissions" ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE "lms_wsp_submissions" FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON "lms_wsp_submissions"');
            DB::statement("CREATE POLICY tenant_isolation ON \"lms_wsp_submissions\" USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_wsp_submissions');
    }
};
