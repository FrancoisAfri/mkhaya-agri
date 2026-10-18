<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSprint1ColumnsToAssetsTable extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('asset_category_id')->nullable()->after('asset_type_id');
            $table->unsignedInteger('location_id')->nullable()->after('asset_category_id');
            $table->string('asset_number', 20)->nullable()->unique()->after('asset_tag');
            $table->string('condition', 20)->nullable()->after('asset_status');
            $table->decimal('purchase_cost', 12, 2)->nullable()->after('price');
            $table->date('purchase_date')->nullable()->after('purchase_cost');
            $table->date('warranty_expiry')->nullable()->after('purchase_date');
            $table->string('sars_allowance_section', 10)->nullable()->after('warranty_expiry');
            $table->date('retention_until')->nullable()->comment('Minimum retention date for compliance')->after('sars_allowance_section');
            $table->date('next_impairment_review_at')->nullable()->after('retention_until');
            $table->string('barcode', 60)->nullable()->index()->after('next_impairment_review_at');
            $table->timestamp('last_audited_at')->nullable()->after('barcode');
            $table->softDeletes();

            $table->foreign('asset_category_id')->references('id')->on('asset_categories')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('asset_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['asset_category_id']);
            $table->dropForeign(['location_id']);
            $table->dropColumn([
                'asset_category_id', 'location_id', 'asset_number', 'condition',
                'purchase_cost', 'purchase_date', 'warranty_expiry', 'sars_allowance_section',
                'retention_until', 'next_impairment_review_at', 'barcode', 'last_audited_at', 'deleted_at',
            ]);
        });
    }
}
