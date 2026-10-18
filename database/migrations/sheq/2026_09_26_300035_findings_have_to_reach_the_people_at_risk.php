<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An investigation whose findings never leave the incident.
 *
 * The module could establish, to ICAM depth and with a second person's approval, that a walkway
 * was never closed beneath a lifting zone — and then that conclusion stayed on the record of the
 * one incident. The crew at the next site doing the same lift over the same kind of walkway had no
 * way of hearing about it.
 *
 * This is the point of investigating. A finding that reaches nobody is an audit artefact; a
 * finding that reaches the people exposed to the same hazard is the only thing that stops the
 * second one. Every mature EHS system has this under some name — safety flash, safety alert,
 * learning event — and it was the one part of the chain with nothing behind it at all.
 *
 * ── Three questions, not free text ───────────────────────────────────────────
 *
 * What happened, why it happened, what we changed. Separate columns rather than one body, because
 * a single box gets filled with the description copied off the incident, and "what we changed" is
 * the half that makes anyone act.
 *
 * ── Acknowledgement is optional, deliberately ────────────────────────────────
 *
 * Requiring a signature from every exposed person turns a two-minute read into an administrative
 * chase, and the alerts that matter most are the ones people read fastest. An issuer who needs
 * proof of receipt asks for it; the default is to get the alert out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_safety_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('reference', 40);

            /** Where the learning came from: usually an approved investigation. */
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('title', 200);

            /** The three questions an alert answers, kept apart so none of them can be skipped. */
            $table->text('what_happened');
            $table->text('why_it_happened')->nullable();
            $table->text('what_we_changed')->nullable();

            /**
             * Who needs to hear it. Null site list means every site — which is the common case,
             * because a hazard that killed somebody at one site is rarely unique to it.
             */
            $table->jsonb('site_ids')->nullable();
            $table->jsonb('audience_roles')->nullable();

            $table->string('severity', 20)->default('INFO');
            $table->boolean('acknowledgement_required')->default(false);

            $table->string('status', 20)->default('DRAFT');
            $table->timestampTz('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('sheq_safety_alert_reads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('alert_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('hr_person_id')->nullable();
            $table->timestampTz('read_at');
            $table->timestamps();

            $table->foreign('alert_id')->references('id')->on('sheq_safety_alerts')->cascadeOnDelete();

            // One read per person per alert: a second click is the same person, not a second
            // reader, and a read count that double-counts is a coverage figure nobody can use.
            $table->unique(['alert_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_safety_alert_reads');
        Schema::dropIfExists('sheq_safety_alerts');
    }
};
