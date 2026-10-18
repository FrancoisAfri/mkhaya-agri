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
        Schema::create('security_ribbons_access', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger("ribbon_id");
            $table->integer('access_level')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('active')->nullable();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            $table->foreign('ribbon_id')
                ->references('id')
                ->on('security_module_ribbons')
                ->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_ribbons_access');
    }
};
