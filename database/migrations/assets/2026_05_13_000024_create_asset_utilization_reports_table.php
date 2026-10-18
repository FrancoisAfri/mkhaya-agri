<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_utilization_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('generated_by');
            $table->string('report_name');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('scope', 20)->default('all')->index();
            $table->unsignedInteger('scope_id')->nullable();
            $table->unsignedInteger('total_assets_in_scope')->default(0);
            $table->json('summary_stats')->nullable();
            $table->json('top_utilized')->nullable();
            $table->json('least_utilized')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['generated_by', 'scope']);
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_utilization_reports');
    }
};
