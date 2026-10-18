<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_compliance_checks', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('rule_id');
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('run_id');
            $table->string('check_result', 20)->default('not_applicable')->index();
            $table->timestamp('last_compliant_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->unsignedInteger('days_overdue')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('rule_id')->references('id')->on('asset_compliance_rules')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('run_id')->references('id')->on('asset_compliance_runs')->cascadeOnDelete();
            $table->unique(['run_id', 'rule_id', 'asset_id']);
            $table->index(['rule_id', 'asset_id', 'check_result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_compliance_checks');
    }
};
