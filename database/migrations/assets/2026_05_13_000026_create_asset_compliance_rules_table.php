<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_compliance_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('rule_type', 40)->index();
            $table->string('applies_to', 20)->default('all')->index();
            $table->unsignedInteger('asset_category_id')->nullable();
            $table->unsignedInteger('interval_days');
            $table->unsignedInteger('grace_period_days')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('created_by');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('asset_category_id')->references('id')->on('asset_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_compliance_rules');
    }
};
