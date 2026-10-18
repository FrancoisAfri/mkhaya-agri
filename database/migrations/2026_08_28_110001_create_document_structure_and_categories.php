<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folder structure, folder permissions and document categories.
 *
 * Three things the repository needs before an organisation can shape it to their own filing:
 * folders that can be archived, moved and scoped to a department; permissions that live on the
 * folder rather than on every document inside it; and a category list the administrator owns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_folders', function (Blueprint $table) {
            // Archiving, not deleting. A folder is where things were filed, so removing one removes
            // the answer to "where did this live" — archived keeps the structure and hides it.
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();

            /*
             * Who a folder is for.
             *
             * 'company' is everybody's — the shared filing cabinet. 'department' belongs to one
             * business unit, and only its members see it unless a permission says otherwise. The
             * unit is a company_unit, which is where departments already live on this platform, so
             * a folder follows the org structure rather than duplicating it.
             */
            $table->string('scope', 20)->default('company');
            $table->unsignedBigInteger('department_unit_id')->nullable();

            // Explicit ordering, because alphabetical is wrong for a filing structure: "Contracts"
            // before "Policies" is a decision somebody makes, not one the alphabet makes for them.
            $table->integer('sort_order')->default(0);

            $table->index(['company_id', 'scope']);
            $table->index(['company_id', 'department_unit_id']);
        });

        /*
         * Permissions on a folder.
         *
         * Held here rather than per document because that is how people think about filing: "the
         * HR team can see Employee Files" is one statement about a folder, not one statement per
         * payslip inside it. Rules inherit down the tree, so a subfolder is covered by its parent
         * unless it says otherwise.
         *
         * A grant names a subject — a role, a user, or a department — and what that subject may do.
         * Roles are the usual case; naming a user is for the exception that always turns up.
         */
        Schema::create('document_folder_permissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('folder_id');

            $table->string('subject_type', 20);          // role | user | department
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_name')->nullable();  // role name, for a readable audit trail

            $table->boolean('can_view')->default(true);
            $table->boolean('can_upload')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'folder_id']);
            $table->unique(['folder_id', 'subject_type', 'subject_id'], 'doc_folder_perm_subject_uq');
        });

        /*
         * Categories.
         *
         * What a document *is*, as distinct from where it is filed — an invoice stays an invoice
         * whether it sits under Finance or under a supplier. Kept as rows rather than an enum
         * because the requirement is that administrators add their own, and an enum makes that a
         * deployment.
         *
         * `is_system` marks the ones shipped with the platform. They can be renamed and hidden but
         * not deleted, because reporting and retention rules reference them.
         */
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('name');
            $table->string('slug', 80);
            $table->text('description')->nullable();

            // For the chip in a listing. A colour is how people find the invoice among forty rows.
            $table->string('colour', 20)->nullable();
            $table->string('icon', 60)->nullable();

            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'slug'], 'document_categories_company_slug_uq');
            $table->index(['company_id', 'is_active']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable();
            $table->index(['company_id', 'category_id']);
        });

        foreach (['document_folder_permissions', 'document_categories'] as $table) {
            // Same tenancy as everything else: the policy allows a row through when it belongs to
            // the session's company, and the default fills company_id from that session variable.
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
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('category_id');
        });

        Schema::dropIfExists('document_categories');
        Schema::dropIfExists('document_folder_permissions');

        Schema::table('document_folders', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'archived_by', 'scope', 'department_unit_id', 'sort_order']);
        });
    }
};
