<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (SHEQ-ARC-1).
 *
 * A domain write and its event insert commit together or not at all; a relay then publishes to the
 * queue. This is the only way to stop the two classic failures: an event published for a write that
 * rolled back, and a write that committed while its event was lost.
 *
 * Consumers are idempotent on `event_id` (SHEQ-ARC-2), so at-least-once delivery is safe. Delivery
 * state lives here rather than in the queue so a failed publish is visible and replayable —
 * exhausted messages stay for inspection instead of vanishing into a dead-letter queue nobody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();          // the consumer idempotency key
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('event_type', 120);           // sheq.incident.reported, sheq.action.overdue
            $table->unsignedSmallInteger('event_version')->default(1);

            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');

            $table->jsonb('payload');
            $table->jsonb('metadata')->nullable();       // actor, device, correlation/causation ids

            $table->timestampTz('available_at')->useCurrent();   // supports delayed publication
            $table->timestampTz('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('failed_at')->nullable();

            $table->timestamps();

            // The relay's hot path: unpublished, due, oldest first.
            $table->index(['published_at', 'available_at', 'id'], 'sheq_outbox_pending_idx');
            $table->index(['company_id', 'event_type']);
            $table->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
        DB::statement("ALTER TABLE sheq_outbox_messages ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement('ALTER TABLE sheq_outbox_messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sheq_outbox_messages FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON sheq_outbox_messages');
        DB::statement("CREATE POLICY tenant_isolation ON sheq_outbox_messages USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_outbox_messages');
    }
};
