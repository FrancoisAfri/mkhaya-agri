<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_boarding_tasks', function (Blueprint $table) {
            $table->string('owner_role', 30)->nullable()->after('sort_order'); // hr|it|finance|manager|facilities
            $table->foreignId('assigned_to')->nullable()->after('owner_role')
                ->constrained('users')->nullOnDelete();
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('pre_boarding_tasks', function (Blueprint $table) {
            $table->dropIndex(['assigned_to', 'status']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn('owner_role');
        });
    }
};
