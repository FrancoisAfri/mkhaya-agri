<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_misuse_flags', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('digital_twin_id')->nullable();
            $table->unsignedInteger('flagged_by')->nullable();
            $table->string('flag_type', 40)->index();
            $table->string('severity', 20)->default('medium')->index();
            $table->string('status', 20)->default('open')->index();
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->timestamp('detected_at');
            $table->unsignedInteger('assigned_investigator')->nullable();
            $table->timestamp('investigated_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->index(['asset_id', 'flag_type', 'status']);
            $table->index(['detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_misuse_flags');
    }
};
