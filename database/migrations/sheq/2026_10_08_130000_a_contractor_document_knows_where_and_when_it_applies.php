<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a contractor document needs to know about itself (CON-8).
 *
 * Until now a document carried an issue date, an expiry date and whether it was mandatory. That is
 * enough to say "this has lapsed" and not enough to say anything useful about it:
 *
 * - **Warning period.** A public liability certificate renewed through a broker needs three months'
 *   notice; a site-specific permit needs a week. One fixed window either nags about everything or
 *   warns too late about the thing that takes longest to replace.
 * - **Site applicability.** A contractor working at four sites has one COID letter and four
 *   site-specific inductions. Demanding the site document at every gate blocks them at three of
 *   them for no reason.
 * - **Activity applicability.** A rigging ticket matters for lifting and is irrelevant to painting.
 *   Without this the file cannot say "they may paint but not lift", which is the answer a permit
 *   issuer actually needs.
 * - **Status.** A document can be supplied and not yet accepted. Treating "received" as "approved"
 *   is how an unchecked certificate satisfies a requirement.
 * - **Version and replacement.** A renewal replaces a specific predecessor. Recording which one
 *   turns a pile of COID letters into a chain, and lets the file show "renewed" rather than
 *   "two documents of the same type".
 *
 * `null` means "everywhere" and "all activities" throughout, deliberately: every document that
 * exists today has no site and no activity recorded, and the safe reading of silence for a
 * compliance document is that it applies, not that it does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sheq_contractor_documents')) {
            return;
        }

        Schema::table('sheq_contractor_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('sheq_contractor_documents', 'warning_days')) {
                // Null means the tenant's default window applies, so changing the default moves
                // every document that never had an opinion of its own.
                $table->unsignedSmallInteger('warning_days')->nullable()
                    ->comment('Days before expiry at which this document starts warning.');
            }

            if (! Schema::hasColumn('sheq_contractor_documents', 'site_id')) {
                $table->foreignId('site_id')->nullable()
                    ->constrained('sheq_sites')->nullOnDelete()
                    ->comment('Null means it applies at every site.');
            }

            if (! Schema::hasColumn('sheq_contractor_documents', 'activities')) {
                $table->jsonb('activities')->nullable()
                    ->comment('Activity codes this document is required for; null means all.');
            }

            if (! Schema::hasColumn('sheq_contractor_documents', 'status')) {
                $table->string('status', 20)->default('ACCEPTED')
                    ->comment('SUPPLIED, ACCEPTED or REJECTED.');
            }

            if (! Schema::hasColumn('sheq_contractor_documents', 'version')) {
                $table->unsignedSmallInteger('version')->default(1);
            }

            if (! Schema::hasColumn('sheq_contractor_documents', 'replaces_document_id')) {
                $table->foreignId('replaces_document_id')->nullable()
                    ->constrained('sheq_contractor_documents')->nullOnDelete();
            }
        });

        /*
         * Existing documents are ACCEPTED, not SUPPLIED.
         *
         * They were filed under a model with no such distinction, and every one of them has already
         * been counted as satisfying its requirement. Defaulting them to SUPPLIED would block every
         * contractor on this installation the moment the column exists — an upgrade that reads as a
         * catastrophic compliance failure across the estate.
         */
        DB::table('sheq_contractor_documents')->whereNull('status')
            ->update(['status' => 'ACCEPTED']);

        if (Schema::hasTable('sheq_settings')
            && ! Schema::hasColumn('sheq_settings', 'contractor_document_warning_days')) {
            Schema::table('sheq_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('contractor_document_warning_days')->nullable()
                    ->comment('Default warning window for contractor documents; 30 when unset.');
            });
        }

        /*
         * The notification gates, per document.
         *
         * CON-8 requires that the same gate never alerts twice. A sent-gate row claimed against a
         * unique index is the only way to guarantee that which survives a retried command, two
         * workers running at once, and somebody pressing the button by hand — all of which the
         * dedupe key on the dispatch queue handles for the *queue*, while this is the record of
         * the decision to notify at all.
         */
        if (! Schema::hasTable('sheq_contractor_document_alerts')) {
            Schema::create('sheq_contractor_document_alerts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('document_id')
                    ->constrained('sheq_contractor_documents')->cascadeOnDelete();

                // The gate, in days before expiry. 0 is the expiry day itself.
                $table->unsignedSmallInteger('gate');

                // The expiry the gate was calculated from. A renewal moves the date, which is a
                // new cycle and earns its own alerts rather than being suppressed by the old ones.
                $table->date('expires_on');

                $table->timestampTz('notified_at')->useCurrent();
                $table->timestamps();

                $table->unique(['document_id', 'gate', 'expires_on'], 'sheq_doc_alert_gate_uniq');
                $table->index(['company_id', 'document_id']);
            });

            DB::statement("ALTER TABLE sheq_contractor_document_alerts ALTER COLUMN company_id
                SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
            DB::statement('ALTER TABLE sheq_contractor_document_alerts ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE sheq_contractor_document_alerts FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON sheq_contractor_document_alerts USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
            ) WITH CHECK (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_contractor_document_alerts');

        if (! Schema::hasTable('sheq_contractor_documents')) {
            return;
        }

        Schema::table('sheq_contractor_documents', function (Blueprint $table) {
            foreach (['replaces_document_id', 'site_id'] as $column) {
                if (Schema::hasColumn('sheq_contractor_documents', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['warning_days', 'activities', 'status', 'version'] as $column) {
                if (Schema::hasColumn('sheq_contractor_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('sheq_settings', 'contractor_document_warning_days')) {
            Schema::table('sheq_settings', function (Blueprint $table) {
                $table->dropColumn('contractor_document_warning_days');
            });
        }
    }
};
