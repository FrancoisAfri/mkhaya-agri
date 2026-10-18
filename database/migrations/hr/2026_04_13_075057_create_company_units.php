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
        Schema::create('company_units', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('name')->index();

            $table->unsignedBigInteger('parent_id')->nullable();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();


//            $table->foreignId('company_id')
//                ->constrained()
//                ->cascadeOnDelete();

            $table->bigInteger('company_id')->index();

            $table->string('type')->nullable();

            $table->integer('sort_order')->default(0);

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->foreign('parent_id')
                ->references('id')
                ->on('company_units')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_units');
    }
};
