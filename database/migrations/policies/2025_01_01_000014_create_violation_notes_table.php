<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violation_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('violation_id')->index();
            $table->unsignedBigInteger('author_id')->index();
            $table->text('content');
            $table->string('attachment_path')->nullable();
            $table->enum('type', ['comment', 'evidence', 'resolution', 'escalation'])->default('comment');
            $table->timestamps();

            $table->foreign('violation_id')->references('id')->on('policy_violations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('violation_notes');
    }
};
