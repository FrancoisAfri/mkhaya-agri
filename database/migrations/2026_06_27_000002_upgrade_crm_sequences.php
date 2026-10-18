<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequences upgrade: working-hours/throttle/exit-goal/track settings on the
 * sequence, A/B variant + goal tracking on enrollments, and a per-send table
 * that powers open/click tracking and per-step / A-B analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_sequences', function (Blueprint $t) {
            if (! Schema::hasColumn('crm_sequences', 'send_window_start')) $t->string('send_window_start', 5)->nullable();   // 'HH:MM'
            if (! Schema::hasColumn('crm_sequences', 'send_window_end'))   $t->string('send_window_end', 5)->nullable();
            if (! Schema::hasColumn('crm_sequences', 'send_days'))         $t->json('send_days')->nullable();               // [1..7] ISO weekday
            if (! Schema::hasColumn('crm_sequences', 'daily_cap'))         $t->unsignedInteger('daily_cap')->nullable();
            if (! Schema::hasColumn('crm_sequences', 'goal_event'))        $t->string('goal_event')->nullable();            // replied|email_clicked|deal_created|lead_converted
            if (! Schema::hasColumn('crm_sequences', 'track_opens'))       $t->boolean('track_opens')->default(true);
            if (! Schema::hasColumn('crm_sequences', 'track_clicks'))      $t->boolean('track_clicks')->default(true);
        });

        Schema::table('crm_sequence_enrollments', function (Blueprint $t) {
            if (! Schema::hasColumn('crm_sequence_enrollments', 'variant'))     $t->string('variant', 2)->nullable();
            if (! Schema::hasColumn('crm_sequence_enrollments', 'completed_at')) $t->timestamp('completed_at')->nullable();
            if (! Schema::hasColumn('crm_sequence_enrollments', 'goal_met_at'))  $t->timestamp('goal_met_at')->nullable();
        });

        if (! Schema::hasTable('crm_sequence_sends')) {
            Schema::create('crm_sequence_sends', function (Blueprint $t) {
                $t->id();
                $t->foreignId('enrollment_id')->constrained('crm_sequence_enrollments')->cascadeOnDelete();
                $t->unsignedBigInteger('sequence_id')->index();
                $t->unsignedSmallInteger('step_index');
                $t->string('channel', 12);
                $t->string('variant', 2)->nullable();
                $t->string('status', 12)->default('sent'); // sent|failed|skipped
                $t->string('token', 40)->nullable()->index();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('opened_at')->nullable();
                $t->unsignedInteger('open_count')->default(0);
                $t->timestamp('clicked_at')->nullable();
                $t->unsignedInteger('click_count')->default(0);
                $t->boolean('replied')->default(false);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sequence_sends');
        Schema::table('crm_sequence_enrollments', fn (Blueprint $t) => $t->dropColumn(['variant', 'completed_at', 'goal_met_at']));
        Schema::table('crm_sequences', fn (Blueprint $t) => $t->dropColumn(['send_window_start', 'send_window_end', 'send_days', 'daily_cap', 'goal_event', 'track_opens', 'track_clicks']));
    }
};
