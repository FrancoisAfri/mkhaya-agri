<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_travel_trips', function (Blueprint $table) {
            $table->boolean('receipt_uploaded')->default(false)->after('notes');
        });

        Schema::table('claim_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('claim_travel_trip_id')->nullable()->after('claim_item_id');
            $table->foreign('claim_travel_trip_id')->references('id')->on('claim_travel_trips')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('claim_attachments', function (Blueprint $table) {
            $table->dropForeign(['claim_travel_trip_id']);
            $table->dropColumn('claim_travel_trip_id');
        });

        Schema::table('claim_travel_trips', function (Blueprint $table) {
            $table->dropColumn('receipt_uploaded');
        });
    }
};
