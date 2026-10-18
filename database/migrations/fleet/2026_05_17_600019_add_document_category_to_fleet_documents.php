<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_documents', function (Blueprint $table) {
            $table->string('document_category', 30)->default('general')->after('document_type');
        });

        // Backfill existing rows using the old name-based logic
        DB::statement("
            UPDATE fleet_documents SET document_category =
            CASE
                WHEN LOWER(document_type) LIKE '%insur%'                              THEN 'insurance'
                WHEN LOWER(document_type) LIKE '%warrant%'                             THEN 'warranty'
                WHEN LOWER(document_type) LIKE '%permit%'
                  OR LOWER(document_type) LIKE '%licen%'                               THEN 'permit_licence'
                ELSE 'general'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('fleet_documents', function (Blueprint $table) {
            $table->dropColumn('document_category');
        });
    }
};
