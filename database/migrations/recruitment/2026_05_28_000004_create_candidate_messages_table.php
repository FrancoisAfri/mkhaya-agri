<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidate_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('recruitment_applications')->cascadeOnDelete();
            $table->string('direction', 10)->default('outbound'); // outbound | inbound
            $table->string('channel', 20)->default('email'); // email | note
            $table->string('subject', 300)->nullable();
            $table->text('body');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();

            $table->index(['application_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidate_messages');
    }
};
