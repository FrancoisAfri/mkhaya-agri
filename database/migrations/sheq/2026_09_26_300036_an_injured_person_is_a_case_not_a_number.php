<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The person who got hurt, from the injury to the day they are back on full duty.
 *
 * `days_lost` and `days_restricted` existed on the incident as two numbers somebody typed in, and
 * those two numbers do more work than almost anything else in the module: they decide whether an
 * injury is reportable, they drive the DIFR the board is shown, and they are what a COIDA claim is
 * assessed on. Typed once, a week after the event, by whoever was updating the record.
 *
 * Nothing tracked the person. There was no record of being booked off and coming back, no expected
 * return date to chase, no restricted-duty period, and no way to answer "who is still off work"
 * without reading incident descriptions. An injury management system that cannot answer that is
 * not managing anybody.
 *
 * ── Days are counted, not entered ────────────────────────────────────────────
 *
 * The lost and restricted day counts are derived from the timeline below and written back to the
 * incident. A figure that is typed drifts from the one that is true, and these are the figures an
 * inspector recalculates and a claims assessor disputes. Keeping the events and deriving the
 * totals means the number can always be explained by pointing at what produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_injury_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('reference', 40);

            $table->unsignedBigInteger('incident_id');
            /** The injured person as recorded on the incident, which may be a contractor's. */
            $table->unsignedBigInteger('incident_person_id')->nullable();
            $table->unsignedBigInteger('hr_person_id')->nullable()->index();

            $table->date('injured_on');

            /**
             * OPEN → off work or restricted; RETURNED → back on full duty; CLOSED → nothing further
             * expected. Kept apart from the incident's own status: an incident can be closed with
             * its findings approved while the person is still in a rehabilitation programme, and
             * conflating the two either holds the investigation open for months or loses the person.
             */
            $table->string('status', 20)->default('OPEN');

            $table->date('expected_return_on')->nullable();
            $table->date('returned_on')->nullable();

            /** Derived from the timeline, never typed. */
            $table->unsignedSmallInteger('days_lost')->default(0);
            $table->unsignedSmallInteger('days_restricted')->default(0);

            $table->text('restrictions')->nullable();
            $table->text('diagnosis')->nullable();

            /** COIDA: the claim this injury was reported under, and when. */
            $table->string('coida_claim_number', 60)->nullable();
            $table->timestampTz('coida_reported_at')->nullable();

            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('incident_id')->references('id')->on('sheq_incidents')->cascadeOnDelete();
            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'status']);

            // One case per injured person per incident: two cases for one person means two sets of
            // day counts, and the incident would be told whichever was written last.
            $table->unique(['incident_id', 'incident_person_id']);
        });

        Schema::create('sheq_injury_case_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('case_id');

            /**
             * BOOKED_OFF / RESTRICTED_DUTY / RETURNED_FULL / MEDICAL_VISIT / REVIEW.
             *
             * The first three carry a period and are what the day counts are computed from; the
             * others are the record of care and do not move the numbers.
             */
            $table->string('event_type', 30);

            $table->date('from_date');
            $table->date('to_date')->nullable();

            $table->string('practitioner', 160)->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('sheq_injury_cases')->cascadeOnDelete();
            $table->index(['case_id', 'from_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_injury_case_events');
        Schema::dropIfExists('sheq_injury_cases');
    }
};
