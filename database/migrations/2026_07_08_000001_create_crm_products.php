<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Products & Services catalog. Leads, accounts and deals can optionally be
 * linked to one catalog item ("which product/service is this for"), which then
 * drives filtering, imports and campaign/sequence targeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type', 20)->default('product'); // product | service
            $table->string('code', 60)->nullable();          // SKU / short code, used for import matching
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency', 8)->default('ZAR');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'type']);
            $table->index('code');
        });

        foreach (['crm_leads', 'crm_accounts', 'crm_deals'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'product_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('product_id')->nullable()->after('id')
                        ->constrained('crm_products')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['crm_leads', 'crm_accounts', 'crm_deals'] as $tableName) {
            if (Schema::hasColumn($tableName, 'product_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('product_id');
                });
            }
        }
        Schema::dropIfExists('crm_products');
    }
};
