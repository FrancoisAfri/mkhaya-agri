<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetCustodyLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_custody_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('assignment_id')->nullable();
            $table->string('action', 40)->index()->comment('assigned|returned|transferred|disposed|found|lost');
            $table->unsignedInteger('from_user_id')->nullable();
            $table->unsignedInteger('to_user_id')->nullable();
            $table->unsignedInteger('from_location_id')->nullable();
            $table->unsignedInteger('to_location_id')->nullable();
            $table->unsignedInteger('performed_by');
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->index(['asset_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_custody_logs');
    }
}
