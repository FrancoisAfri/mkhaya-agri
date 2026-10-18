<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            $table->string('extraction_provider')->nullable()->after('extraction_status');
            $table->string('extraction_model')->nullable()->after('extraction_provider');
            $table->json('raw_ai_output')->nullable()->after('extraction_model');
            $table->text('extraction_error')->nullable()->after('raw_ai_output');
            $table->timestamp('extracted_at')->nullable()->after('extraction_error');
        });
    }

    public function down(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropColumn([
                'extraction_provider',
                'extraction_model',
                'raw_ai_output',
                'extraction_error',
                'extracted_at',
            ]);
        });
    }
};