<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('rule_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by')->index();
            $table->text('reason');
            $table->text('evidence')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();          // null = indefinite (rare, must be flagged)
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired', 'revoked'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->boolean('auto_expire_notified')->default(false);
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_exceptions');
    }
};
