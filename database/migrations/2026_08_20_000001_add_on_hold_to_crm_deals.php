<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Pause this — come back to us in a few months."
 *
 * Until now a rep hearing that had two options, and both lost the deal. Marking it lost writes a
 * loss into the numbers for a deal nobody lost, and poisons the win-rate the sales manager is
 * measured on. Leaving it open keeps it in the forecast at its stage probability, so the pipeline
 * reports revenue that everybody knows is not coming this quarter — and the deal sits on the board
 * ageing quietly until somebody deletes it.
 *
 * On hold is the third answer. The deal keeps its stage, its value and its history, drops out of
 * the forecast, and carries the date it is due back. `hold_stage_id` remembers where it was so it
 * returns to the same column rather than to the top of the funnel — a deal that was at proposal
 * before the pause is still at proposal after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            $table->text('hold_reason')->nullable()->after('lost_ai_advice');
            // What the client actually said, in their words. "Budget freeze until March" is the
            // difference between a deal worth waking and one worth writing off.
            $table->date('hold_until')->nullable()->after('hold_reason');
            $table->timestamp('held_at')->nullable()->after('hold_until');
            $table->unsignedBigInteger('held_by')->nullable()->after('held_at');
            $table->unsignedBigInteger('hold_stage_id')->nullable()->after('held_by');
            // The cadence that keeps the relationship warm while the deal sleeps. Optional: some
            // holds want silence, and sending anyway is how a pause becomes a lost account.
            $table->unsignedBigInteger('hold_sequence_id')->nullable()->after('hold_stage_id');
            // Set when the wake-up has run, so a deal cannot be resumed twice by a retried cron.
            $table->timestamp('hold_woken_at')->nullable()->after('hold_sequence_id');

            // The daily wake-up scans exactly this pair.
            $table->index(['status', 'hold_until'], 'crm_deals_hold_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            $table->dropIndex('crm_deals_hold_due_idx');
            $table->dropColumn([
                'hold_reason', 'hold_until', 'held_at', 'held_by',
                'hold_stage_id', 'hold_sequence_id', 'hold_woken_at',
            ]);
        });
    }
};
