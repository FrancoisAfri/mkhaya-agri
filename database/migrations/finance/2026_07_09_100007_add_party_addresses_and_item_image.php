<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Party addresses (so a quote's delivery/postal address auto-fills from the
 * selected customer) and an item image stored in the DB (base64).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_parties', function (Blueprint $table) {
            $table->text('address')->nullable()->after('phone');
            $table->string('city', 120)->nullable()->after('address');
            $table->string('country', 120)->nullable()->after('city');
            $table->text('postal_address')->nullable()->after('country');
        });

        Schema::table('fin_items', function (Blueprint $table) {
            $table->longText('image')->nullable()->after('description'); // base64 data URI
        });
    }

    public function down(): void
    {
        Schema::table('fin_parties', fn (Blueprint $table) => $table->dropColumn(['address', 'city', 'country', 'postal_address']));
        Schema::table('fin_items', fn (Blueprint $table) => $table->dropColumn('image'));
    }
};
