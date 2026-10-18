<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Widen all fleet file-path columns from varchar(500) to text so they can
 * hold the JSON blob produced by App\Core\User\Trait\FileStorage::saveFileToDatabase().
 * Also adds disposal_document_data to fleet_vehicles (previously computed but never persisted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_vehicles', function (Blueprint $table) {
            $table->text('registration_document_path')->nullable()->change();
            $table->text('disposal_document_data')->nullable()->after('disposal_reason');
        });

        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->text('supporting_document_path')->nullable()->change();
            $table->text('collection_document_path')->nullable()->change();
            $table->text('collection_image_path')->nullable()->change();
        });

        Schema::table('fleet_booking_tollgates', function (Blueprint $table) {
            $table->text('receipt_path')->nullable()->change();
        });

        Schema::table('fleet_booking_inspections', function (Blueprint $table) {
            $table->text('signature_path')->nullable()->change();
        });

        Schema::table('fleet_documents', function (Blueprint $table) {
            $table->text('file_path')->nullable()->change();
        });

        Schema::table('fleet_vehicle_images', function (Blueprint $table) {
            $table->text('file_path')->change();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_vehicles', function (Blueprint $table) {
            $table->string('registration_document_path', 500)->nullable()->change();
            $table->dropColumn('disposal_document_data');
        });

        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->string('supporting_document_path', 500)->nullable()->change();
            $table->string('collection_document_path', 500)->nullable()->change();
            $table->string('collection_image_path', 500)->nullable()->change();
        });

        Schema::table('fleet_booking_tollgates', function (Blueprint $table) {
            $table->string('receipt_path', 500)->nullable()->change();
        });

        Schema::table('fleet_booking_inspections', function (Blueprint $table) {
            $table->string('signature_path', 500)->nullable()->change();
        });

        Schema::table('fleet_documents', function (Blueprint $table) {
            $table->string('file_path', 500)->nullable()->change();
        });

        Schema::table('fleet_vehicle_images', function (Blueprint $table) {
            $table->string('file_path', 500)->change();
        });
    }
};
