<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency + governing law / jurisdiction for contracts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('currency', 3)->default('ZAR')->after('contract_value');
            $table->string('governing_law')->nullable()->after('periodicity');
            $table->string('jurisdiction')->nullable()->after('governing_law');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['currency', 'governing_law', 'jurisdiction']);
        });
    }
};
