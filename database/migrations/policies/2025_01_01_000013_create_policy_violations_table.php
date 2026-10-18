<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('rule_id')->index();
            $table->unsignedBigInteger('clause_id')->nullable()->index();
            $table->unsignedBigInteger('event_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();       // violating user
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->enum('outcome', ['blocked', 'warned', 'escalated', 'logged']);
            $table->json('event_snapshot');                       // payload at time of violation
            $table->text('explanation')->nullable();              // AI-generated explanation
            $table->enum('status', ['open', 'under_investigation', 'resolved', 'dismissed'])->default('open');
            $table->unsignedBigInteger('investigator_id')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_violations');
    }
};
