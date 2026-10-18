<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notification routing (SHEQ-INC-4 / SHEQ-INC-5).
 *
 * The requirement is a matrix — `(incident_type × actual_severity × potential_severity × site) →
 * recipients × channel × delay` — so it is stored as data a tenant can edit, not as code. Sites
 * differ: a night-shift smelter escalates a severity-3 near miss that a depot would file.
 *
 * Two design points worth stating, because both are the difference between a notification system
 * that works at 02:00 and one that does not:
 *
 * 1. **Rules are additive filters, not a first-match table.** Every rule whose criteria fit gets to
 *    contribute recipients. A first-match table quietly loses the group-level escalation the moment
 *    someone adds a narrower site rule above it — a failure mode that only shows up during the
 *    incident you most needed it for. The union is then deduplicated per (recipient, channel)
 *    keeping the *shortest* delay, so overlapping rules cannot page one person four times.
 *
 * 2. **The fatality / section 24 floor is not a rule.** SHEQ-INC-5 mandates immediate multi-channel
 *    alert for those events. If it lived in the matrix a tenant could delete it, so it is applied
 *    unconditionally by the router and merely *recorded* as a dispatch with a null rule_id.
 *
 * Dispatches are rows rather than queued jobs because an unsent statutory alert has to be visible
 * and replayable. A job that died in a worker is invisible; a PENDING row past its send_after is an
 * operational fact somebody can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // ── The match criteria. Null means "any" for every one of them, so an empty rule is a
            //    catch-all — deliberately, because that is the rule a tenant writes first.
            $table->string('event_type', 60)->default('incident.reported');
            $table->jsonb('incident_types')->nullable();        // ["INJURY","NEAR_MISS"]
            $table->jsonb('statutory_categories')->nullable();  // ["OHSA_S24(a)"]
            $table->unsignedTinyInteger('actual_severity_min')->nullable();
            $table->unsignedTinyInteger('actual_severity_max')->nullable();
            $table->unsignedTinyInteger('potential_severity_min')->nullable();
            $table->unsignedTinyInteger('potential_severity_max')->nullable();
            // A site rule covers the site and everything under it: sites are an ltree hierarchy, and
            // a regional manager's rule that stopped at the region node would be useless.
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->boolean('include_child_sites')->default(true);
            $table->boolean('reportable_only')->nullable();     // null = any, true = statutory only

            // ── Who and how.
            $table->jsonb('recipient_roles')->nullable();       // ["SHEQ_MANAGER","SITE_MANAGER"]
            $table->jsonb('recipient_user_ids')->nullable();
            $table->jsonb('recipient_contacts')->nullable();    // [{name,email,phone}] — externals
            $table->boolean('include_escalation_contacts')->default(false);
            $table->jsonb('channels');                          // ["EMAIL","SMS","PUSH","VOICE"]
            $table->unsignedSmallInteger('delay_minutes')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);  // display order only
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'event_type', 'is_active']);
            $table->index(['company_id', 'site_id']);
        });

        /**
         * The nominated escalation contacts (SHEQ-INC-5: "voice call to a nominated escalation
         * number"). Held apart from users because the person who must be woken for a fatality is
         * often a duty phone, a group safety line or an external emergency number — not a login.
         */
        Schema::create('sheq_escalation_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            $table->string('name');
            $table->string('role_label', 120)->nullable();      // "Group SHE Manager", "Duty phone"
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Who gets the 02:00 voice call, and in what order the calls are attempted.
            $table->boolean('is_voice_target')->default(true);
            $table->unsignedSmallInteger('call_order')->default(1);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'site_id', 'is_active']);
        });

        Schema::create('sheq_notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Null when the floor produced it — the row still records *why* under `trigger_reason`.
            $table->foreignId('rule_id')->nullable()
                ->constrained('sheq_notification_rules')->nullOnDelete();
            $table->string('trigger_reason', 40)->default('RULE');   // RULE|STATUTORY_FLOOR|FATALITY

            $table->string('event_type', 60);
            $table->string('subject_type', 40);                 // INCIDENT
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_reference', 60)->nullable(); // INC-2026-000123, for the queue view

            $table->string('channel', 20);                      // EMAIL|SMS|PUSH|VOICE|WHATSAPP
            $table->string('recipient_kind', 20);               // USER|CONTACT|ROLE
            $table->foreignId('recipient_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('recipient_label')->nullable();
            $table->string('recipient_address')->nullable();    // email address or msisdn
            $table->string('recipient_role', 60)->nullable();   // why this person was chosen
            $table->string('dedupe_key', 120);                  // recipient identity, collapsed

            $table->string('severity', 20)->default('NORMAL');  // NORMAL|HIGH|CRITICAL
            $table->jsonb('payload')->nullable();               // subject, body, deep link

            $table->timestampTz('send_after');
            $table->string('status', 20)->default('PENDING');   // PENDING|SENT|FAILED|CANCELLED
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            /**
             * One notification per person per channel per event. Four overlapping rules must not
             * become four SMSs — the router collapses them before insert, and this index is the
             * guarantee that a concurrent second attempt cannot undo that.
             */
            $table->unique(['company_id', 'subject_type', 'subject_id', 'channel', 'dedupe_key'],
                'sheq_dispatch_dedupe_uniq');

            // The sender's hot path: due, unsent, oldest first.
            $table->index(['status', 'send_after', 'id'], 'sheq_dispatch_pending_idx');
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });

        $this->applyTenantRls([
            'sheq_notification_rules', 'sheq_escalation_contacts', 'sheq_notification_dispatches',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_notification_dispatches');
        Schema::dropIfExists('sheq_escalation_contacts');
        Schema::dropIfExists('sheq_notification_rules');
    }

    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
