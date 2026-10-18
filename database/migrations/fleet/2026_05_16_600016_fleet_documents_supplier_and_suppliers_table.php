<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_documents', function (Blueprint $table) {
            $table->string('supplier', 100)->nullable()->after('document_type');
        });

        Schema::create('fleet_document_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_document_suppliers');
        Schema::table('fleet_documents', function (Blueprint $table) {
            $table->dropColumn('supplier');
        });
    }
};
