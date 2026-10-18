<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules that belong to a kind of document rather than to the repository.
 *
 * Every organisation has some of these and none of them fit a single global setting. A contract
 * needs a counterparty and an end date; a certificate needs an issuer and an expiry; a policy needs
 * neither and does need approval before anybody reads it. Requiring all of that of everything would
 * make uploading a scanned receipt an ordeal, and requiring none of it means the contracts register
 * has holes in it that are only discovered when somebody needs the counterparty.
 *
 * Three things hang off a category. Extra fields that documents of that kind carry, and whether
 * they are required. A numbering pattern, so a contract gets CTR-2027-0041 without anybody keeping
 * a spreadsheet of the last number used. And a default approval workflow, so "policies go to legal"
 * is a property of policies rather than something remembered on each upload.
 *
 * The field values live in a JSON column on the document rather than in a table of their own. They
 * are read with the document, written with the document, and never queried across documents by
 * value — a table would buy a join and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_categories', function (Blueprint $table) {
            /*
             * The pattern, with placeholders: CTR-{YYYY}-{####}. Kept as a string rather than a set
             * of columns for prefix, year and width, because the thing people actually want to
             * change is where the pieces sit relative to each other.
             */
            $table->string('number_pattern', 60)->nullable();
            $table->unsignedInteger('number_next')->default(1);
            $table->unsignedBigInteger('default_workflow_id')->nullable();
            // Whether a document of this kind may be filed without going through that workflow.
            $table->boolean('workflow_required')->default(false);
        });

        Schema::create('document_category_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('category_id');

            // The key the value is stored under, and the label somebody reads. Separate because a
            // label renamed must not orphan every value already recorded under the old wording.
            $table->string('key', 60);
            $table->string('label', 120);
            $table->string('type', 20)->default('text');
            $table->json('options')->nullable();
            $table->string('hint', 255)->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'key']);
            $table->index(['company_id', 'category_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('tags');
        });

        foreach (['document_category_fields'] as $table) {
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
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('custom_fields'));
        Schema::dropIfExists('document_category_fields');
        Schema::table('document_categories', fn (Blueprint $table) => $table->dropColumn([
            'number_pattern', 'number_next', 'default_workflow_id', 'workflow_required',
        ]));
    }
};
