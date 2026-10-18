<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('color', 20)->default('#6c757d');
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_present')->default(false);
            $table->boolean('is_absent')->default(false);
            $table->boolean('is_late')->default(false);
            $table->boolean('is_leave')->default(false);
            $table->boolean('is_public_holiday')->default(false);
            $table->tinyInteger('status')->default(1)->comment('1=active,0=inactive');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_statuses');
    }
};
