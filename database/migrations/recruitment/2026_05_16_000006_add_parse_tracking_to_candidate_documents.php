<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->timestamp('parsed_at')->nullable()->after('verified_at');
            $table->string('parse_status', 20)->nullable()->after('parsed_at');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->dropColumn(['parsed_at', 'parse_status']);
        });
    }
};
