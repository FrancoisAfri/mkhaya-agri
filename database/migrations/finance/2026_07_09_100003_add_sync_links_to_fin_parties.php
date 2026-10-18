<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let fin_parties link back to their source of truth so Accounting can either
 * stand alone (source=manual) or sync from other modules: customers from CRM
 * accounts, suppliers from Supplier Management. Synced rows are read-only here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_parties', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('kind'); // manual | crm | supplier_mgmt
            $table->unsignedBigInteger('crm_account_id')->nullable()->after('source');
            $table->unsignedBigInteger('supplier_id')->nullable()->after('crm_account_id');

            $table->index('crm_account_id');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('fin_parties', function (Blueprint $table) {
            $table->dropColumn(['source', 'crm_account_id', 'supplier_id']);
        });
    }
};
