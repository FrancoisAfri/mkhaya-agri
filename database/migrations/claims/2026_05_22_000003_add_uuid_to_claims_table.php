<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Back-fill existing rows
        DB::table('claims')->whereNull('uuid')->get()->each(function ($row) {
            DB::table('claims')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
