<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_exit_clearances', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('initiated_by');
            $table->date('target_exit_date');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('cleared_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('approval_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_exit_clearances');
    }
};
