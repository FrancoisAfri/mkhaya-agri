<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-020 -- notifications, approvals and escalations.
 *
 * A rule per kind of event says who hears about it: holders of named permissions (tenants design
 * their own roles, so routing by permission survives a role being renamed), holders of named
 * roles, named people, and whoever is responsible for the thing itself -- the order's supervisor,
 * the operation's operator, the action's owner. A rule can be confined to one factory, and only
 * people who may see that factory are told.
 *
 * Every alert is logged per person. The same alert for the same thing is not sent again inside
 * the rule's quiet period -- it is counted against the first one -- and many alerts of one kind
 * for one person in one run arrive as a single summary. Left unresolved past the escalation
 * time, an alert goes once more to the rule's escalation permissions.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_notification_rules', 'mfg_notification_log'];

    public function up(): void
    {
        Schema::create('mfg_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->boolean('is_active')->default(true);
            $table->foreignId('factory_id')->nullable()->constrained('mfg_factories')->nullOnDelete();
            $table->jsonb('recipient_permissions');
            $table->jsonb('recipient_roles')->nullable();
            $table->jsonb('recipient_user_ids')->nullable();
            $table->boolean('notify_responsible')->default(true);
            $table->jsonb('channels');                              // database | mail
            $table->unsignedInteger('lead_hours')->default(24);     // how early, or how long before it counts
            $table->unsignedInteger('suppress_hours')->default(24);
            $table->unsignedInteger('escalate_after_hours')->nullable();
            $table->jsonb('escalate_to_permissions')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'event_type', 'factory_id'], 'mfg_notify_rule_uq');
        });

        Schema::create('mfg_notification_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('mfg_notification_rules')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('dedupe_key');
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('factory_id')->nullable()->constrained('mfg_factories')->nullOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('level')->default(0);      // 0 first notice, 1 escalation
            $table->string('reason', 16);                           // PERMISSION|ROLE|NAMED|RESPONSIBLE|ESCALATION
            $table->string('title');
            $table->text('message');
            $table->string('url')->nullable();
            $table->jsonb('channels');
            $table->unsignedInteger('occurrences')->default(1);
            $table->boolean('in_digest')->default(false);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'dedupe_key', 'recipient_id', 'level'], 'mfg_notify_log_key_idx');
            $table->index(['company_id', 'event_type', 'last_seen_at'], 'mfg_notify_log_type_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            // One rule for every factory at most: NULLs never collide in a plain unique key.
            DB::statement('CREATE UNIQUE INDEX mfg_notify_rule_all_factories_uq ON mfg_notification_rules (company_id, event_type) WHERE factory_id IS NULL');

            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TABLES as $t) {
                DB::statement("ALTER TABLE {$t} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
                DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }

        (new ManufacturingModuleSeeder)->run();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
