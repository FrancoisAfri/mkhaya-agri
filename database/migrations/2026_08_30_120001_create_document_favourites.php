<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents somebody wants to find again.
 *
 * Per person, not per company: a favourite is a private convenience, and one person starring the
 * leave policy should not put it on everybody else's list.
 *
 * Deliberately not a "recent documents" table. What somebody looked at last is already recorded —
 * the audit trail holds every view with a timestamp — and a second store of the same fact would
 * drift from it within a week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_favourites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->nullable();

            // Starring twice is a click, not two favourites.
            $table->unique(['document_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE document_favourites ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_favourites ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_favourites FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_favourites_tenant ON document_favourites USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_favourites');
    }
};
