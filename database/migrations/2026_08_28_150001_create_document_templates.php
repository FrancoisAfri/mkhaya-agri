<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Templates, and a record of what was generated from them.
 *
 * A template is a document with holes in it. The holes are filled from records the platform already
 * holds, which is the entire point — an employment contract typed by hand is a contract with the
 * salary from the last one still in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('name');
            $table->text('description')->nullable();

            // What the generated document will be filed as, so a generated contract lands in the
            // right place without the person choosing twice.
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();

            /*
             * What this template is written about — employee, supplier, contract.
             *
             * Declared rather than inferred, because it decides which merge fields exist. A
             * template offering {{employee.salary}} when it is generated from a supplier would
             * produce a blank where a number should be, which is the worst of the three outcomes.
             */
            $table->string('subject_relation', 40)->nullable();

            // The body, as HTML. Sanitised on the way in — an administrator writes these, but a
            // template is rendered for everybody who generates from it.
            $table->longText('body')->nullable();

            // What the document is called once generated. Merge fields work here too, so the file
            // is named "Employment contract — J Dlamini" rather than "Employment contract (3)".
            $table->string('filename_pattern')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'subject_relation']);
        });

        /*
         * What was generated, from which template, about which record.
         *
         * Kept because "where did this contract come from" is asked of generated documents far more
         * often than of uploaded ones, and because a template edited afterwards must not change
         * what the answer looks like.
         */
        Schema::create('document_generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();

            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The values used, as they were at the time. A salary that changes next month must not
            // rewrite the contract that was generated this month.
            $table->json('merge_values')->nullable();

            // Fields the template asked for and the record could not answer. Recorded rather than
            // silently blanked, because a contract with an empty salary line is a contract somebody
            // signs without noticing.
            $table->json('missing_fields')->nullable();

            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'template_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        foreach (['document_templates', 'document_generations'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant ON {$table} USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_generations');
        Schema::dropIfExists('document_templates');
    }
};
