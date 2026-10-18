<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured metadata, and the links that let the rest of the platform use the repository.
 *
 * The metadata columns are the straightforward part. The links are the point: a document is
 * evidence about something — an employee, a contract, a vehicle, a project — and until it can say
 * which, the library is a filing cabinet nobody else can reach into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // The organisation's own reference, not ours. Generated when nobody supplies one,
            // because a document with no number is one you cannot ask for over the phone.
            $table->string('document_number', 60)->nullable();

            /*
             * Type sits under category: the category says a document is a Contract, the type says
             * it is a Service Level Agreement. Free text rather than another table, because this is
             * the level at which every organisation's vocabulary differs and a fixed list would be
             * wrong for all of them.
             */
            $table->string('document_type', 120)->nullable();

            // Which department's document this is. Separate from the folder it sits in — filing and
            // ownership are different questions, and a document can be filed centrally while
            // belonging to Finance.
            $table->unsignedBigInteger('department_unit_id')->nullable();

            // The person accountable for it, as distinct from whoever happened to upload it. Those
            // are the same person often enough that conflating them looks harmless, and different
            // often enough that it matters when a policy needs reviewing.
            $table->unsignedBigInteger('owner_id')->nullable();

            /*
             * Three dates that are genuinely different and get confused constantly:
             *
             *   created_at      when it was put into the system
             *   document_date   the date printed on the document itself
             *   effective_date  when it starts to apply, which can be later than either
             *   expiry_date     when it stops
             *
             * A tax clearance certificate issued in March, effective from April, expiring in
             * September, uploaded in June has four different dates and every one of them is asked
             * for by somebody.
             */
            $table->date('document_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();

            /*
             * A level rather than a flag. `is_confidential` answers yes or no, and organisations
             * need the middle: internal documents that are not secret but are not public either.
             * The existing booleans stay and are kept in step, because access control already reads
             * them and rewriting that at the same time as adding a field is how both get broken.
             */
            $table->string('confidentiality', 20)->default('internal');

            $table->index(['company_id', 'expiry_date']);
            $table->index(['company_id', 'document_number']);
            $table->index(['company_id', 'owner_id']);
            $table->index(['company_id', 'department_unit_id']);
        });

        /*
         * What a document is about.
         *
         * Polymorphic rather than a column per relation, for two reasons. One document is often
         * about several things at once — a signed contract concerns the supplier, the project and
         * the contract record — and a column each would force a choice between them. And a module
         * added later can link its own records without a migration on this table, which is the
         * difference between a repository the platform uses and one it works around.
         *
         * `relation` is the role the link plays, so a document can point at a person as its subject
         * and at another as its approver without the two being indistinguishable.
         */
        Schema::create('document_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');

            $table->string('relation', 40);           // employee, supplier, contract, project, …
            $table->string('linkable_type', 191);     // the model class on the other end
            $table->unsignedBigInteger('linkable_id');

            // What it was called when it was linked. Kept so a listing reads properly without
            // loading a record from another module — and still reads after that record is gone.
            $table->string('label')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_id']);
            $table->index(['linkable_type', 'linkable_id']);
            $table->unique(['document_id', 'relation', 'linkable_type', 'linkable_id'], 'document_links_unique');
        });

        DB::statement("ALTER TABLE document_links ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_links ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_links FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_links_tenant ON document_links USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");

        // Existing documents keep the meaning their flags already carried, so nothing changes
        // level the moment the column appears.
        DB::statement("UPDATE documents SET confidentiality = 'restricted' WHERE is_legally_sensitive = true");
        DB::statement("UPDATE documents SET confidentiality = 'confidential' WHERE is_confidential = true AND is_legally_sensitive = false");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'document_number', 'document_type', 'department_unit_id', 'owner_id',
                'document_date', 'effective_date', 'expiry_date', 'confidentiality',
            ]);
        });
    }
};
