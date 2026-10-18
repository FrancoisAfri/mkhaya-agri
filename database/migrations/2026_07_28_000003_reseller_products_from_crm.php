<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The products/services a reseller may sell come from the CRM product catalog
 * (crm_products), not the platform service_offerings. The platform's own catalog is the
 * crm_products with a null company_id, managed by the platform admin at /crm/products.
 * Swaps the reseller pivot to reference crm_products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('reseller_service_offering');

        Schema::create('reseller_crm_product', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reseller_id');
            $t->unsignedBigInteger('crm_product_id');
            $t->timestamps();
            $t->unique(['reseller_id', 'crm_product_id'], 'reseller_product_unique');
            $t->index('reseller_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_crm_product');

        Schema::create('reseller_service_offering', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reseller_id');
            $t->unsignedBigInteger('service_offering_id');
            $t->timestamps();
            $t->unique(['reseller_id', 'service_offering_id'], 'reseller_offering_unique');
            $t->index('reseller_id');
        });
    }
};
