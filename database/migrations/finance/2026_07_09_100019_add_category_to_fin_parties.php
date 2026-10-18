<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Customer/supplier category (2.1) — for grouping and filtering. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_parties', function (Blueprint $table) {
            $table->string('category', 80)->nullable()->after('kind');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('fin_parties', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
