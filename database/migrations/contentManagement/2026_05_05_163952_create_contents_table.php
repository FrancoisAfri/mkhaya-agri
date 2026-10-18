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
        Schema::create('contents', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('assets_file_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_unit_id')->nullable();

            $table->string('title');
            $table->string('description')->nullable();
            $table->text('summary')->nullable();
            $table->string('icon')->nullable();

            $table->time('event_start')->nullable();
            $table->time('event_end')->nullable();
            $table->timestamp('event_date')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->smallInteger('type')->index(); // 1=announcement, 2=news, 3=event 4=internal notice

            $table->boolean('is_active')->default(false);
            $table->json('meta_data')->nullable();

            $table->foreign('assets_file_id')
                ->references('id')
                ->on('assets_files')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
