<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetCategoriesTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->decimal('sars_wear_rate', 6, 4)->nullable()->comment('Annual wear rate e.g. 0.3333 = 33.33%');
            $table->string('sars_allowance_section', 10)->nullable();
            $table->string('default_depreciation_method', 30)->nullable();
            $table->unsignedTinyInteger('default_useful_life_years')->nullable();
            $table->smallInteger('status')->default(1)->index();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('asset_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
}
