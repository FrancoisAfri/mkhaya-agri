<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_items', function (Blueprint $table) {
            $table->date('item_date')->nullable()->after('claim_id');
        });
    }

    public function down(): void
    {
        Schema::table('claim_items', function (Blueprint $table) {
            $table->dropColumn('item_date');
        });
    }
};
