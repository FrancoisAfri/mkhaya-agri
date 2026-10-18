<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
            $table->string('name', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Use USING to truncate any overlong values rather than failing
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE assets ALTER COLUMN description TYPE varchar(255) USING left(description, 255)'
        );
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE assets ALTER COLUMN name TYPE varchar(255) USING left(name, 255)'
        );
    }
};
