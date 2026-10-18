<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('on_behalf_of_id')->nullable()->after('requester_id');
            $table->jsonb('passenger_ids')->nullable()->after('passenger_count');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->dropColumn(['on_behalf_of_id', 'passenger_ids']);
        });
    }
};
