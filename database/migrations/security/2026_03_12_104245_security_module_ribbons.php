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
        Schema::create('security_module_ribbons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->integer('module_id')->nullable();
            $table->integer('sort_order')->nullable();
            $table->integer('access_level')->nullable();
            $table->integer('active')->nullable();
            $table->string('ribbon_name')->nullable();
            $table->string('ribbon_path')->nullable();
            $table->string('description')->nullable();
            $table->string('font_awesome')->nullable();
                 $table->foreign('module_id')
                ->references('id')
                ->on('security_modules')
                ->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_module_ribbons');
    }
};
