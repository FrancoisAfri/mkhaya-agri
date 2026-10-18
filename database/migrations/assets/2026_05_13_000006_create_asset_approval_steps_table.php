<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetApprovalStepsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_approval_steps', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('approval_id');
            $table->unsignedTinyInteger('step_number');
            $table->unsignedInteger('approver_id');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('approval_id')->references('id')->on('asset_approvals')->cascadeOnDelete();
            $table->unique(['approval_id', 'step_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_approval_steps');
    }
}
