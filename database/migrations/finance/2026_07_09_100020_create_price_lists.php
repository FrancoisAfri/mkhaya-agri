<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price lists (2.1): named per-item price sets (e.g. Retail, Wholesale) that can
 * be assigned to a customer, overriding the item's default sales price on
 * quotes and invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_price_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fin_price_list_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('price_list_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('price', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['price_list_id', 'item_id']);
        });

        Schema::table('fin_parties', function (Blueprint $table) {
            $table->unsignedBigInteger('price_list_id')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('fin_parties', fn (Blueprint $table) => $table->dropColumn('price_list_id'));
        Schema::dropIfExists('fin_price_list_items');
        Schema::dropIfExists('fin_price_lists');
    }
};
