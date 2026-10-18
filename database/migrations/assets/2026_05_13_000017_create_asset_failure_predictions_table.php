<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetFailurePredictionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_failure_predictions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('digital_twin_id')->nullable();
            $table->string('prediction_source', 30)->default('rule_engine')->comment('rule_engine|ai_model');
            $table->string('failure_component')->nullable();
            $table->text('failure_description');
            $table->float('confidence_score')->default(0)->comment('0-100');
            $table->date('predicted_failure_date')->nullable();
            $table->text('recommended_action')->nullable();
            $table->decimal('estimated_repair_cost', 12, 2)->nullable();
            $table->boolean('is_actioned')->default(false)->index();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->index(['asset_id', 'is_actioned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_failure_predictions');
    }
}
