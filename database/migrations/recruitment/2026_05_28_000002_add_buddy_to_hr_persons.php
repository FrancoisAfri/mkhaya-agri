<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_persons', function (Blueprint $table) {
            $table->foreignId('buddy_user_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->string('department', 100)->nullable()->after('buddy_user_id');
            $table->string('job_title', 150)->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('hr_persons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('buddy_user_id');
            $table->dropColumn(['department', 'job_title']);
        });
    }
};
