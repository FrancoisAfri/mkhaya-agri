<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store supplier compliance documents inside the database (base64 JSON blob from
 * App\Core\User\Trait\FileStorage) rather than on disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_documents', function (Blueprint $table) {
            $table->longText('file_data')->nullable()->after('attachment_path');
            $table->string('file_name')->nullable()->after('file_data');
            $table->string('file_mime')->nullable()->after('file_name');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_mime');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_documents', function (Blueprint $table) {
            $table->dropColumn(['file_data', 'file_name', 'file_mime', 'file_size']);
        });
    }
};
