<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_modules', function (Blueprint $table) {
            $table->bigIncrements("id");
            $table->unsignedBigInteger("category_id");
            $table->uuid('uuid')->index();
            $table->string('name')->nullable();
            $table->string('path')->nullable();
            $table->string('code_name')->nullable();
            $table->integer('active')->nullable();
            $table->string('font_awesome')->nullable();
            $table->string('glyph_icon')->nullable();
            $table->foreign('category_id')
                ->references('id')
                ->on('security_categories')
                ->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_modules', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::dropIfExists('security_modules');
    }
};
