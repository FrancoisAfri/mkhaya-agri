<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reseller may be an individual or a company. Records the type and (for a company) its
 * registration number. `name` holds the person's or the company's name accordingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $t) {
            $t->string('type', 12)->default('individual')->after('name'); // individual | company
            $t->string('registration_number')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $t) {
            $t->dropColumn(['type', 'registration_number']);
        });
    }
};
