<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_compliance_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('run_by');
            $table->string('scope', 20)->default('all')->index();
            $table->unsignedInteger('asset_category_id')->nullable();
            $table->unsignedInteger('total_checked')->default(0);
            $table->unsignedInteger('pass_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->unsignedInteger('overdue_count')->default(0);
            $table->unsignedInteger('not_applicable_count')->default(0);
            $table->decimal('compliance_rate', 5, 2)->default(0);
            $table->timestamp('run_at');
            $table->timestamps();

            $table->index(['run_by', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_compliance_runs');
    }
};
