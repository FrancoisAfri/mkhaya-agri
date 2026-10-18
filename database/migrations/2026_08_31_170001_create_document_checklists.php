<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What should be on file, and what is missing."
 *
 * Every organisation has packs of documents that travel together — a new employee, a new supplier,
 * a tender submission, a loan application — and every one of them is currently tracked in somebody's
 * head or in a spreadsheet beside the repository. The repository can say what it holds; it cannot
 * say what it is missing, and missing is the thing people are actually asked about.
 *
 * A template is the list as a rule ("a new employee pack is these seven documents"). A checklist is
 * that rule applied to one case ("Thandi's pack"), so changing the rule next year does not rewrite
 * what was required of everybody hired this year.
 *
 * An item ticks itself when a matching document is filed into the folder. A checklist somebody has
 * to tick by hand is a second record of the same fact, and the two disagree within a week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('document_checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('template_id');
            $table->string('title', 200);
            $table->string('description', 500)->nullable();
            // What kind of document satisfies this. The tick happens by matching a document filed
            // into the folder against it, so an item without one can only ever be ticked by hand.
            $table->unsignedBigInteger('category_id')->nullable();
            /*
             * Optional items are worth having. "Proof of qualification, where the role requires it"
             * belongs on the list — leaving it off means it is forgotten in the cases that do need
             * it, and marking it required means every pack is permanently incomplete.
             */
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'sort_order']);
        });

        Schema::create('document_checklists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('name', 200);
            // Whose pack this is. Free text rather than a user id: the subject is as often a
            // supplier, a tender or a property as it is an employee.
            $table->string('subject', 200)->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'completed_at']);
            $table->index(['company_id', 'folder_id']);
        });

        Schema::create('document_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('checklist_id');
            $table->string('title', 200);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // What satisfied it, and whether anybody has been asked for it yet.
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamp('satisfied_at')->nullable();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['checklist_id', 'sort_order']);
            $table->index(['company_id', 'satisfied_at']);
        });

        foreach ([
            'document_checklist_templates',
            'document_checklist_template_items',
            'document_checklists',
            'document_checklist_items',
        ] as $table) {
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
        Schema::dropIfExists('document_checklist_items');
        Schema::dropIfExists('document_checklists');
        Schema::dropIfExists('document_checklist_template_items');
        Schema::dropIfExists('document_checklist_templates');
    }
};
