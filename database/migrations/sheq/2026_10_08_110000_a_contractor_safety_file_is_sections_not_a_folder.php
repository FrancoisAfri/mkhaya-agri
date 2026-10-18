<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The digital contractor safety file (CON-6).
 *
 * A safety file is the folder a contractor hands over at the gate, and it is the single most
 * paper-bound artefact in this domain: a lever arch file with dividers, carried to site, photocopied
 * when it expires, and impossible to audit without physically holding it. What makes it a folder
 * rather than a list of documents is the *dividers* — the sections, and the fact that an empty
 * divider is itself a finding.
 *
 * So the file is modelled as its sections. Each row says what a section is called, whether it is
 * demanded of this contractor, and in what order it appears. What *satisfies* a section is not
 * stored here: that lives in the shipped catalogue, because it is a property of the kind of section
 * rather than of a tenant's choice, and because a tenant editing "how is this satisfied" could
 * silently detach a section from the documents already filed under it.
 *
 * Per tenant, and copy-on-write. A tenant with no rows uses the shipped profile for their industry,
 * so a fresh installation has a working file rather than an empty one, and nothing has to be
 * backfilled across every company on this migration. The moment they change anything, their own
 * rows exist and the shipped profile stops applying.
 *
 * The profile itself is recorded on `sheq_settings` so the screens can say which one is in force
 * and offer to change it. Industry is a choice, not an inference: inferring it from the regulatory
 * pack works for mining and tells you nothing about a cleaning company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sheq_contractors')) {
            return;
        }

        if (! Schema::hasTable('sheq_safety_file_sections')) {
            Schema::create('sheq_safety_file_sections', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();

                // The catalogue code. Not a foreign key: the catalogue is shipped code, and a
                // tenant keeping a section the catalogue later drops should keep their documents.
                $table->string('code', 60);

                // Named per tenant, because "COID" is a South African word and this runs elsewhere.
                $table->string('name', 160);
                $table->text('describes')->nullable();

                // Whether the file is incomplete without it. The whole point of the model.
                $table->boolean('is_required')->default(false);
                $table->unsignedSmallInteger('position')->default(0);
                $table->boolean('is_active')->default(true);

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'code']);
                $table->index(['company_id', 'is_active', 'position']);
            });
        }

        /*
         * Tenant isolation, matching every other SHEQ table: the session variable defaults the
         * column, the policy enforces it, and FORCE applies it to the table owner too.
         *
         * The policy admits an unstamped connection — a migration, a seeder, a platform
         * administrator with no company resolved. Without that allowance the install itself would
         * be locked out of the table it just created.
         */
        DB::statement("ALTER TABLE sheq_safety_file_sections ALTER COLUMN company_id
            SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE sheq_safety_file_sections ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sheq_safety_file_sections FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON sheq_safety_file_sections USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        ) WITH CHECK (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");

        if (Schema::hasTable('sheq_settings')
            && ! Schema::hasColumn('sheq_settings', 'contractor_safety_file_profile')) {
            Schema::table('sheq_settings', function (Blueprint $table) {
                $table->string('contractor_safety_file_profile', 40)->nullable()
                    ->comment('Shipped safety-file profile in force; null means the general one.');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_safety_file_sections');

        if (Schema::hasColumn('sheq_settings', 'contractor_safety_file_profile')) {
            Schema::table('sheq_settings', function (Blueprint $table) {
                $table->dropColumn('contractor_safety_file_profile');
            });
        }
    }
};
