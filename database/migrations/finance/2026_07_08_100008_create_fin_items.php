<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items — the Accounting module's own products/services master with correct
 * sales & cost prices and GL account mapping (unlike CRM products, which are
 * categories). Quote/invoice/bill lines pick an item to auto-fill price, tax
 * and the income/expense account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('code', 60);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30)->default('each');       // each, hour, day, km, kg…
            $table->string('type', 20)->default('service');    // service | product
            $table->decimal('sales_price', 18, 4)->default(0);
            $table->decimal('cost_price', 18, 4)->default(0);
            $table->unsignedBigInteger('sales_account_id')->nullable();    // income
            $table->unsignedBigInteger('purchase_account_id')->nullable(); // expense
            $table->decimal('tax_rate', 6, 3)->default(15);
            $table->string('tax_code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('is_active');
        });

        foreach (['fin_invoice_lines', 'fin_quote_lines'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('item_id')->nullable()->after('account_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['fin_invoice_lines', 'fin_quote_lines'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('item_id'));
        }
        Schema::dropIfExists('fin_items');
    }
};
