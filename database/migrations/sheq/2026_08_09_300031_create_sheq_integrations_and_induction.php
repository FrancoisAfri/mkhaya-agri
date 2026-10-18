<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access-control integrations, site induction, and bulk onboarding.
 *
 * These three exist to close one loop: **compliance that is computed but not enforced is not
 * enforcement.** SHEQ already knows whether a person's induction is valid, their medical current and
 * their contractor approved. Until something acts on that at the gate, it is a report — and the
 * person walks in anyway.
 *
 * `sheq_integrations` deliberately mirrors `fleet_integrations` column for column. The platform
 * already has a working pattern for third-party connections — provider, endpoint, key, secret,
 * extra config, last sync status — and an administrator who has configured a telematics feed should
 * recognise this screen immediately. Copying a good local convention beats inventing a better one.
 *
 * `sheq_access_events` records every decision the gate made, including the refusals. That is the
 * half people forget: knowing who was turned away, and why, is what tells a safety manager their
 * induction backlog is real rather than theoretical.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Third-party connections ───────────────────────────────────────────────────────────
        Schema::create('sheq_integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            /** ACCESS_CONTROL, CLOCK_IN, BIOMETRIC, VISITOR_KIOSK. */
            $table->string('integration_type', 30)->index();

            /** The adapter key — impro, zkteco, suprema, gallagher, ers_biometrics, uniclox… */
            $table->string('provider', 40)->index();
            $table->string('label', 120);

            $table->string('api_endpoint', 255)->nullable();

            /**
             * Encrypted at rest. These credentials open a door — literally — and a database read
             * that yields them is a physical security incident, not just a data one.
             */
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();

            /** Per-provider extras: site ids, reader groups, device serials, tenant codes. */
            $table->jsonb('additional_config')->nullable();

            /** Which SHEQ site this connection controls access to. */
            $table->unsignedBigInteger('site_id')->nullable()->index();

            /**
             * Whether a refusal actually stops somebody, or is only recorded.
             *
             * Off by default, and this is the most important default in the file. Turning on
             * enforcement at a live gate before the data is clean strands a shift outside in the
             * dark. Every site runs in monitor mode first, sees who *would* have been refused, fixes
             * the backlog, and then enforces.
             */
            $table->boolean('enforce_access')->default(false);

            $table->boolean('active')->default(true);
            $table->string('last_sync_status', 20)->nullable();
            $table->text('last_sync_message')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Every gate decision, including the refusals ───────────────────────────────────────
        Schema::create('sheq_access_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('integration_id')->nullable()->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            /** Who — an employee, a contractor worker, or a visitor. */
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('identifier', 80)->nullable()->index();
            $table->string('person_name', 160)->nullable();

            /** ENTRY, EXIT, CLOCK_IN, CLOCK_OUT. */
            $table->string('direction', 20)->default('ENTRY');

            $table->boolean('allowed');

            /**
             * Why, in the words the guard reads on the screen.
             *
             * A refusal that says "denied" starts an argument at the boom. One that says "site
             * induction expired 12 March" ends it, and tells the person what to go and fix.
             */
            $table->jsonb('reasons')->nullable();

            /** True when the connection was in monitor mode: recorded, not enforced. */
            $table->boolean('was_monitor_only')->default(false);

            $table->string('device_ref', 80)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['company_id', 'site_id', 'occurred_at']);
        });

        // ── Site induction ────────────────────────────────────────────────────────────────────
        Schema::create('sheq_inductions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('code', 40);
            $table->string('title', 200);

            /** VISITOR, CONTRACTOR, EMPLOYEE, DRIVER — different audiences, different content. */
            $table->string('audience', 30)->default('VISITOR')->index();

            /**
             * Sections of content, each with a heading and body, optionally an image.
             *
             * Held as data rather than a linked document because an induction must render on a
             * phone at a gate with poor signal, and a 4 MB PDF does not.
             */
            $table->jsonb('content');

            /** Questions that must be answered correctly before entry. */
            $table->jsonb('questions')->nullable();
            $table->unsignedInteger('pass_mark')->default(100);

            /** The agreement text presented for acceptance — NDA, site rules, PPE undertaking. */
            $table->text('nda_text')->nullable();
            $table->boolean('requires_nda')->default(false);
            $table->boolean('requires_signature')->default(true);

            /** How long a completion stays valid before it must be redone. */
            $table->unsignedInteger('validity_months')->default(12);

            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code', 'version'], 'sheq_inductions_code_version_unique');
        });

        Schema::create('sheq_induction_completions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('induction_id')->index();

            /** The completion may belong to somebody who has no user account at all. */
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->string('person_name', 160);
            $table->string('person_identifier', 80)->nullable()->index();

            /** The version they actually saw — content changes, and the record must not drift. */
            $table->unsignedInteger('induction_version');

            $table->jsonb('answers')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->boolean('passed')->default(false);

            $table->boolean('nda_accepted')->default(false);
            $table->timestamp('nda_accepted_at')->nullable();
            $table->longText('signature')->nullable();

            $table->timestamp('completed_at');
            $table->date('expires_on')->nullable()->index();

            $table->string('language', 10)->default('en');
            $table->string('completed_via', 20)->default('WEB');

            $table->timestamps();

            $table->index(['company_id', 'person_identifier', 'expires_on']);
        });

        // ── Bulk onboarding ───────────────────────────────────────────────────────────────────
        Schema::create('sheq_bulk_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            /** CONTRACTOR_WORKERS, EQUIPMENT, PPE_ITEMS, MEDICAL_RECORDS, ENV_READINGS. */
            $table->string('import_type', 40)->index();

            $table->string('original_filename', 255)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            /**
             * Row-level errors, kept with the row number and the value that failed.
             *
             * The difference between a usable import and an infuriating one: "row 47, ID number
             * 850101580008 is 12 digits, expected 13" versus "import failed".
             */
            $table->jsonb('errors')->nullable();

            /** PENDING, VALIDATING, VALIDATED, IMPORTING, COMPLETED, FAILED. */
            $table->string('status', 20)->default('PENDING')->index();

            /**
             * A dry run parses and validates without writing.
             *
             * Somebody uploading four hundred contractor workers wants to know what is wrong before
             * half of them land in the database.
             */
            $table->boolean('dry_run')->default(true);

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            foreach (['sheq_integrations', 'sheq_access_events', 'sheq_inductions',
                'sheq_induction_completions', 'sheq_bulk_imports'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        foreach (['sheq_bulk_imports', 'sheq_induction_completions', 'sheq_inductions',
            'sheq_access_events', 'sheq_integrations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
