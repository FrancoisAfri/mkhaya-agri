<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the optional template/sample file for a supplier document type inside the
 * database (base64 JSON blob from App\Core\User\Trait\FileStorage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_document_types', function (Blueprint $table) {
            $table->longText('template_data')->nullable()->after('attachment_path');
            $table->string('template_name')->nullable()->after('template_data');
            $table->string('template_mime')->nullable()->after('template_name');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_document_types', function (Blueprint $table) {
            $table->dropColumn(['template_data', 'template_name', 'template_mime']);
        });
    }
};
