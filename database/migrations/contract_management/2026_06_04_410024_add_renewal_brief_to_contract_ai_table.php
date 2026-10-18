<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-generated renewal brief: a recommendation (renew / renegotiate / exit) plus
 * rationale and suggested actions for an expiring contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_ai', function (Blueprint $table) {
            $table->string('renewal_recommendation')->nullable()->after('recommendations');
            $table->longText('renewal_brief')->nullable()->after('renewal_recommendation');
            $table->timestamp('renewal_brief_at')->nullable()->after('renewal_brief');
        });
    }

    public function down(): void
    {
        Schema::table('contract_ai', function (Blueprint $table) {
            $table->dropColumn(['renewal_recommendation', 'renewal_brief', 'renewal_brief_at']);
        });
    }
};
