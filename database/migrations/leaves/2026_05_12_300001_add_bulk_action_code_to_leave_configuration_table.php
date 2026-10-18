<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->string('bulk_action_code', 64)->nullable()->after('ers_token_number')
                ->comment('Required to authorise bulk (department/division-wide) balance adjustments');
        });
    }

    public function down(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->dropColumn('bulk_action_code');
        });
    }
};
