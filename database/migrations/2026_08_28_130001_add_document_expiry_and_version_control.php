<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expiry management and version control.
 *
 * The expiry side is what turns a repository into something an organisation relies on: a tax
 * clearance that lapsed is a tender that cannot be submitted, and nobody finds out by browsing a
 * folder. The version side is what makes replacing an expired document safe — the old one stays,
 * and it can be put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_settings', function (Blueprint $table) {
            /*
             * When to warn, in days before expiry.
             *
             * Configurable per company because the right answer depends on what the renewal costs.
             * A B-BBEE certificate takes months to re-verify and ninety days is barely enough; a
             * driver's licence is an afternoon. Shipped defaults are the six from the requirement.
             */
            $table->json('expiry_reminder_days')->nullable();

            // Off leaves everything else working: expiry is still recorded and shown, nobody is
            // emailed about it. An installation mid-rollout needs that.
            $table->boolean('expiry_reminders_enabled')->default(true);

            /*
             * Who hears about it, beyond the document's own owner.
             *
             * Roles rather than named people, because the person who should chase a lapsed
             * insurance certificate is whoever holds that job this month.
             */
            $table->json('expiry_notify_roles')->nullable();
        });

        /*
         * What has already been sent.
         *
         * Without this the daily sweep re-sends every reminder every day, and a notification people
         * learn to ignore is worse than none. One row per document per threshold, so a document
         * crossing ninety days is told once and then left alone until sixty.
         */
        Schema::create('document_expiry_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');

            // Days before expiry this reminder was for; 0 is the expiry itself.
            $table->integer('threshold_days');

            // The expiry it was sent about. A renewed document has a new date, and its reminders
            // start again — matching on the date is what makes that work without a reset step.
            $table->date('expiry_date');

            $table->timestamp('sent_at')->nullable();
            $table->integer('recipients')->default(0);
            $table->timestamps();

            $table->unique(['document_id', 'threshold_days', 'expiry_date'], 'document_expiry_reminder_uq');
            $table->index(['company_id', 'sent_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            /*
             * Whether a lapse should stop things happening elsewhere.
             *
             * Off by default and deliberately so. A document that quietly blocks a supplier from
             * being paid is a support call nobody can diagnose, so blocking is something somebody
             * turns on for the certificate where it matters — insurance, tax clearance — and knows
             * they turned on.
             */
            $table->boolean('blocks_when_expired')->default(false);

            // Recorded when the sweep first sees it lapse, so "expired last Tuesday" is answerable
            // and the transition can be shown in the history.
            $table->timestamp('expired_at')->nullable();
        });

        Schema::table('document_versions', function (Blueprint $table) {
            /*
             * A version people recognise: 1.0, 1.1, 2.0.
             *
             * The integer stays and remains the ordering — it is what every existing row has, and
             * what the unique key and "latest" queries use. Major and minor sit alongside it as the
             * label, so a correction to a typo is 1.1 and a re-negotiated contract is 2.0. The
             * distinction is the uploader's to make; nothing can infer it from the bytes.
             */
            $table->unsignedInteger('major')->default(1);
            $table->unsignedInteger('minor')->default(0);

            // Which version this one replaced, so the chain reads in both directions without
            // inferring it from the number — versions can be restored, and a restore is a new
            // version whose predecessor is not the one numerically before it.
            $table->unsignedBigInteger('supersedes_id')->nullable();

            // Set when a version was created by restoring an earlier one, so the history can say
            // "restored from 1.1" rather than presenting it as an ordinary upload.
            $table->unsignedBigInteger('restored_from_id')->nullable();

            $table->index(['document_id', 'major', 'minor']);
        });

        DB::statement("ALTER TABLE document_expiry_reminders ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_expiry_reminders ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_expiry_reminders FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_expiry_reminders_tenant ON document_expiry_reminders USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");

        // Existing versions get a label matching their number, so nothing displays as blank while
        // the two schemes coexist.
        DB::statement('UPDATE document_versions SET major = version, minor = 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_expiry_reminders');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['blocks_when_expired', 'expired_at']);
        });

        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn(['major', 'minor', 'supersedes_id', 'restored_from_id']);
        });

        Schema::table('document_settings', function (Blueprint $table) {
            $table->dropColumn(['expiry_reminder_days', 'expiry_reminders_enabled', 'expiry_notify_roles']);
        });
    }
};
