<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NL draft needs clause_id since ParseNaturalLanguageRuleAction stores it
        Schema::table('nl_rule_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('clause_id')->nullable()->after('policy_id');
            $table->index('clause_id');
            $table->foreign('clause_id')->references('id')->on('policy_clauses')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('nl_rule_drafts', function (Blueprint $table) {
            $table->dropForeign(['clause_id']);
            $table->dropIndex(['clause_id']);
            $table->dropColumn('clause_id');
        });
    }
};
