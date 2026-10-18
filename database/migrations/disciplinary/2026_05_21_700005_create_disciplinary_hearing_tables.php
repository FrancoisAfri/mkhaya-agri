<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Disciplinary hearings ─────────────────────────────────────────────
        Schema::create('disciplinary_hearings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('hearing_number', 30)->unique();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('chairperson_id')->nullable();
            $table->unsignedBigInteger('initiator_id')->nullable();    // person bringing the charge
            $table->unsignedBigInteger('hr_rep_id')->nullable();
            // scheduled | in_progress | adjourned | completed | cancelled
            $table->string('status', 30)->default('scheduled');
            $table->string('hearing_type', 30)->default('formal');     // formal | informal | appeal
            $table->dateTime('scheduled_at');
            $table->string('venue', 200)->nullable();
            $table->string('venue_type', 30)->default('physical');     // physical | virtual | hybrid
            $table->string('meeting_link', 500)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('notice_issued')->default(false);
            $table->date('notice_issued_date')->nullable();
            $table->boolean('pack_generated')->default(false);
            $table->text('adjournment_reason')->nullable();
            $table->dateTime('adjourned_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('case_id');
            $table->index('chairperson_id');
            $table->index('status');
            $table->index('scheduled_at');

            $table->foreign('case_id')->references('id')->on('disciplinary_cases')->cascadeOnDelete();
        });

        // ── Hearing attendees ─────────────────────────────────────────────────
        Schema::create('disciplinary_hearing_attendees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hearing_id');
            $table->unsignedBigInteger('user_id')->nullable();
            // employee | employee_rep | chairperson | initiator | hr_rep | witness | observer
            $table->string('role', 40)->default('witness');
            $table->string('external_name', 150)->nullable();          // for external representatives
            $table->string('external_org', 150)->nullable();
            $table->boolean('attended')->default(false);
            $table->boolean('apology_received')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('hearing_id');

            $table->foreign('hearing_id')
                ->references('id')->on('disciplinary_hearings')->cascadeOnDelete();
        });

        // ── Hearing minutes ───────────────────────────────────────────────────
        Schema::create('disciplinary_hearing_minutes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hearing_id');
            // opening | charge_reading | plea | evidence | witness_testimony | closing | procedural | general
            $table->string('minute_type', 40)->default('general');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('speaker_name', 150)->nullable();
            $table->text('content');
            $table->timestamp('minute_time')->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->timestamps();

            $table->index('hearing_id');
            $table->index('sequence');

            $table->foreign('hearing_id')
                ->references('id')->on('disciplinary_hearings')->cascadeOnDelete();
        });

        // ── Hearing outcomes ──────────────────────────────────────────────────
        Schema::create('disciplinary_hearing_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hearing_id')->unique();        // one outcome per hearing
            $table->unsignedBigInteger('outcome_id')->nullable();
            $table->unsignedBigInteger('sanction_id')->nullable();
            $table->string('verdict', 30)->nullable();                  // guilty | not_guilty | inconclusive
            $table->text('reasoning');
            $table->text('sanction_detail')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('appeal_deadline')->nullable();
            $table->boolean('appeal_lodged')->default(false);
            $table->text('appeal_grounds')->nullable();
            $table->unsignedBigInteger('decided_by');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index('hearing_id');

            $table->foreign('hearing_id')
                ->references('id')->on('disciplinary_hearings')->cascadeOnDelete();
            $table->foreign('outcome_id')
                ->references('id')->on('disciplinary_outcomes')->nullOnDelete();
            $table->foreign('sanction_id')
                ->references('id')->on('disciplinary_sanctions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_hearing_outcomes');
        Schema::dropIfExists('disciplinary_hearing_minutes');
        Schema::dropIfExists('disciplinary_hearing_attendees');
        Schema::dropIfExists('disciplinary_hearings');
    }
};
