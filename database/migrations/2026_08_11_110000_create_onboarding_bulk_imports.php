<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A record of each bulk onboarding upload.
 *
 * Kept rather than discarded because a spreadsheet import is the one operation where a user cannot
 * see what happened: two hundred rows go in and some number of people come out. Without a record
 * there is nowhere to answer "did row 147 work?" — and re-uploading to find out creates duplicates.
 *
 * `row_errors` holds the per-row rejections so the failures can be shown, corrected and re-uploaded
 * without redoing the rows that succeeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_bulk_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('filename');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            // Which catalogue or tenant template every row was onboarded onto, when the file did
            // not name one per row.
            $table->unsignedBigInteger('default_template_id')->nullable();
            $table->json('row_errors')->nullable();
            $table->string('status')->default('completed');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        // Tenancy is row-level here as everywhere else. Unlike the template catalogue there is no
        // shared-read policy: one tenant's import history is nobody else's business.
        DB::statement('ALTER TABLE onboarding_bulk_imports ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE onboarding_bulk_imports FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON onboarding_bulk_imports
            USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = (NULLIF(current_setting('app.company_id', true), ''))::bigint
            )
            WITH CHECK (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = (NULLIF(current_setting('app.company_id', true), ''))::bigint
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_bulk_imports');
    }
};
