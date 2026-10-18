<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full quote layout (Sage-style Process Quote): customer reference, sales rep,
 * document discount %, and delivery / postal addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_quotes', function (Blueprint $table) {
            $table->string('customer_ref', 120)->nullable()->after('description');
            $table->string('sales_rep', 120)->nullable()->after('customer_ref');
            $table->decimal('discount_percent', 6, 3)->default(0)->after('sales_rep');
            $table->decimal('discount_amount', 18, 2)->default(0)->after('discount_percent');
            $table->text('delivery_address')->nullable()->after('discount_amount');
            $table->text('postal_address')->nullable()->after('delivery_address');
        });
    }

    public function down(): void
    {
        Schema::table('fin_quotes', function (Blueprint $table) {
            $table->dropColumn(['customer_ref', 'sales_rep', 'discount_percent', 'discount_amount', 'delivery_address', 'postal_address']);
        });
    }
};
