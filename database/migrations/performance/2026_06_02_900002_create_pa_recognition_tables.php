<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Continuous recognition / kudos feed: public praise posts, emoji reactions and
 * threaded comments. This is the "social" layer that drives daily engagement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pa_recognitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('giver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->string('badge')->nullable();          // e.g. team_player, innovation, customer_hero
            $table->string('value_tag')->nullable();      // company value referenced
            $table->text('message');
            $table->boolean('is_public')->default(true);
            $table->json('mentioned_user_ids')->nullable();
            $table->timestamps();

            $table->index(['receiver_id', 'created_at']);
            $table->index(['is_public', 'created_at']);
        });

        Schema::create('pa_recognition_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recognition_id')->constrained('pa_recognitions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16)->default('👏');
            $table->timestamps();

            $table->unique(['recognition_id', 'user_id', 'emoji']);
        });

        Schema::create('pa_recognition_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recognition_id')->constrained('pa_recognitions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_recognition_comments');
        Schema::dropIfExists('pa_recognition_reactions');
        Schema::dropIfExists('pa_recognitions');
    }
};
