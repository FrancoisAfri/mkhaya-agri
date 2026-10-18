<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Relations
            $table->unsignedBigInteger('assets_file_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_unit_id')->nullable();

            // Infos principales
            $table->string('title');
            $table->text('description')->nullable();

            // Type de ressource
            $table->smallInteger('type')->index();
            // 1=Form, 2=Legal, 3=Handbook, 4=HealthSafety,
            // 5=Template, 6=Training, 7=SOP, 8=Download

            // Access control
            $table->boolean('internal_only')->default(false);
            $table->boolean('is_active')->default(true);

            // Metadata dynamique
            $table->json('meta_data')->nullable();

            // Dates utiles (optionnel)
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            // Foreign keys
            $table->foreign('assets_file_id')
                ->references('id')
                ->on('assets_files')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('company_unit_id')
                ->references('id')
                ->on('company_units')
                ->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
