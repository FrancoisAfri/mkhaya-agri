<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetApprovalCommentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_approval_comments', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('approval_id');
            $table->unsignedInteger('user_id');
            $table->text('comment');
            $table->timestamps();

            $table->foreign('approval_id')->references('id')->on('asset_approvals')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_approval_comments');
    }
}
