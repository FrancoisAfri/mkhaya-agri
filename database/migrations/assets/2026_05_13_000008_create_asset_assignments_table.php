<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetAssignmentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('assigned_to')->comment('User / employee ID');
            $table->unsignedInteger('assigned_by');
            $table->unsignedInteger('location_id')->nullable();
            $table->string('assignment_type', 20)->default('permanent');
            $table->date('effective_date');
            $table->date('return_due_date')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->boolean('is_current')->default(true)->index();
            $table->unsignedInteger('approval_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('asset_locations')->nullOnDelete();
            $table->foreign('approval_id')->references('id')->on('asset_approvals')->nullOnDelete();
            $table->index(['asset_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
    }
}
