<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('recruitment_interviews')->cascadeOnDelete();
            $table->text('question');
            $table->enum('category', ['technical', 'behavioural', 'situational', 'leadership', 'cultural']);
            $table->boolean('ai_generated')->default(false);
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->timestamps();

            $table->index(['interview_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_questions');
    }
};
