<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the contract document inside the database (base64 JSON blob produced by
 * App\Core\User\Trait\FileStorage) instead of on the filesystem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->longText('file_data')->nullable()->after('file_path');
            $table->string('file_name')->nullable()->after('file_data');
            $table->string('file_mime')->nullable()->after('file_name');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_mime');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['file_data', 'file_name', 'file_mime', 'file_size']);
        });
    }
};
