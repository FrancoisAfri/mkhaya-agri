<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Named groups of people, so access is given to a job rather than to a list of names.
 *
 * Roles and departments already exist on this platform and are used for exactly this — but neither
 * fits "the external auditors for the 2026 audit". A role is a job somebody holds across the whole
 * system; a department is where they sit; a group is a set of people assembled for a purpose, often
 * temporarily, often crossing both.
 *
 * The point is what happens six months later. Access granted to four named people is four grants
 * nobody remembers, and when a fifth auditor joins somebody adds them by hand and gets it slightly
 * wrong. Access granted to a group is one row, and the membership is the thing that changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('name', 120);
            $table->string('description')->nullable();

            /*
             * When the group itself stops applying.
             *
             * Distinct from an expiring grant: the auditors' engagement ends, and every access they
             * were ever given should end with it rather than being revoked one folder at a time.
             */
            $table->timestamp('expires_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // Two groups with the same name is how somebody grants access to the wrong one.
            $table->unique(['company_id', 'name']);
        });

        Schema::create('document_group_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('user_id');

            // A person can be added to a group for a while — a secondee, somebody covering leave —
            // without the group itself being temporary.
            $table->timestamp('expires_at')->nullable();

            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['group_id', 'user_id']);
            $table->index('user_id');
        });

        foreach (['document_groups', 'document_group_members'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant ON {$table} USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
            )");
        }

        Schema::table('document_folder_permissions', function (Blueprint $table) {
            /*
             * Temporary access to a folder.
             *
             * §9 asks for access that ends by itself, and a permission that has to be remembered
             * and revoked is one that outlives its reason — the auditor who still has the Finance
             * folder two years later got it that way.
             */
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_folder_permissions', fn (Blueprint $t) => $t->dropColumn('expires_at'));
        Schema::dropIfExists('document_group_members');
        Schema::dropIfExists('document_groups');
    }
};
