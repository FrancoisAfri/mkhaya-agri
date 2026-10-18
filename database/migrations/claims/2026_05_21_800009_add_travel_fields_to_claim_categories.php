<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_categories', function (Blueprint $table) {
            $table->boolean('is_travel')->default(false)->after('active');
            $table->decimal('km_rate', 10, 4)->nullable()->after('is_travel')->comment('Reimbursement rate per kilometre (e.g. 4.18)');
        });
    }

    public function down(): void
    {
        Schema::table('claim_categories', function (Blueprint $table) {
            $table->dropColumn(['is_travel', 'km_rate']);
        });
    }
};
