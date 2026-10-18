<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidate_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('recruitment_applications')->cascadeOnDelete();
            $table->string('referee_name', 150);
            $table->string('referee_email', 200)->nullable();
            $table->string('referee_phone', 50)->nullable();
            $table->string('referee_company', 150)->nullable();
            $table->string('referee_title', 150)->nullable();
            $table->string('relationship', 100)->nullable(); // e.g. direct manager, colleague
            $table->string('status', 30)->default('pending'); // pending, contacted, received
            $table->text('response_notes')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidate_references');
    }
};
