<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('clause_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('natural_language_input')->nullable(); // original NL before AI parsing
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('action_on_breach', ['block', 'warn', 'escalate', 'log_only'])->default('warn');
            $table->boolean('requires_exception_approval')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_rules');
    }
};
