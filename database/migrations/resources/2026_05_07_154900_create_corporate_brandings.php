<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_brandings', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Relations
            $table->unsignedBigInteger('company_unit_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // Branding info
            $table->string('name');

            // Logos (assets file realtion)
            $table->unsignedBigInteger('logo_light_id')->nullable();
            $table->unsignedBigInteger('logo_dark_id')->nullable();
            $table->unsignedBigInteger('favicon_id')->nullable();

            // Couleurs
            $table->string('primary_color')->nullable();
            $table->string('secondary_color')->nullable();
            $table->string('accent_color')->nullable();
            $table->string('background_color')->nullable();
            $table->string('text_color')->nullable();

            // Typographie
            $table->string('font_family')->nullable();
            $table->string('font_url')->nullable();

            $table->json('meta_data')->nullable();

            $table->boolean('is_active')->default(true);

            // Foreign keys
            $table->foreign('company_unit_id')
                ->references('id')
                ->on('company_units')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('logo_light_id')
                ->references('id')
                ->on('assets_files')
                ->nullOnDelete();

            $table->foreign('logo_dark_id')
                ->references('id')
                ->on('assets_files')
                ->nullOnDelete();

            $table->foreign('favicon_id')
                ->references('id')
                ->on('assets_files')
                ->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_brandings');
    }
};
