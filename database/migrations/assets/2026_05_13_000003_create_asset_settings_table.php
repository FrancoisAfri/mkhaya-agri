<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetSettingsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('cast', 20)->default('string');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_settings');
    }
}
