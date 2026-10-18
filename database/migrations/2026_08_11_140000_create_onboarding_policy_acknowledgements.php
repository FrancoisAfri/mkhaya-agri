<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a new hire actually accepted, and which version of it.
 *
 * `policy_acknowledgement` was a bare task type: a checkbox saying somebody ticked something. It
 * recorded no policy, no version and no signature, so "did they accept the harassment policy?" was
 * answerable only as "a task with that title was marked done" — which proves nothing if the policy
 * has been revised since, and a policy that has never been revised is unusual.
 *
 * Modelled on sheq_document_acknowledgements, which already solves this for SHEQ: the acknowledged
 * *version* is the point, along with the time, the IP and the signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('onboarding_task_id')->nullable()->index();
            $table->unsignedBigInteger('hr_person_id')->index();
            // The version, not just the policy. A policy revised after acceptance means the old
            // acknowledgement no longer covers what the document now says.
            $table->unsignedBigInteger('policy_version_id')->index();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at');
            $table->string('signature')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->timestamps();

            // One acceptance per person per version: re-reading a policy is not a second agreement,
            // and a duplicate row would overstate the evidence.
            $table->unique(['hr_person_id', 'policy_version_id'], 'onboarding_ack_person_version_unique');
        });

        DB::statement('ALTER TABLE onboarding_policy_acknowledgements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE onboarding_policy_acknowledgements FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON onboarding_policy_acknowledgements
            USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = (NULLIF(current_setting('app.company_id', true), ''))::bigint
            )
            WITH CHECK (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = (NULLIF(current_setting('app.company_id', true), ''))::bigint
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_policy_acknowledgements');
    }
};
