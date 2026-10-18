<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_social_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider', 30); // linkedin | facebook | indeed
            $table->string('display_name', 150);
            $table->text('access_token')->nullable();   // encrypted via model cast
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'is_active']);
        });

        Schema::create('recruitment_social_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vacancy_id')
                ->constrained('recruitment_vacancies')
                ->cascadeOnDelete();
            $table->foreignId('social_account_id')
                ->constrained('recruitment_social_accounts')
                ->cascadeOnDelete();
            $table->string('status', 20)->default('queued'); // queued | published | failed
            $table->string('external_post_id', 191)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['vacancy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_social_posts');
        Schema::dropIfExists('recruitment_social_accounts');
    }
};
