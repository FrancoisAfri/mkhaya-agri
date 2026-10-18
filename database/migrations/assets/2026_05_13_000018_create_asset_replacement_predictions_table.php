<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetReplacementPredictionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_replacement_predictions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('digital_twin_id')->nullable();
            $table->date('recommended_replacement_date')->nullable();
            $table->string('replacement_reason')->nullable();
            $table->decimal('current_book_value', 12, 2)->nullable();
            $table->decimal('estimated_replacement_cost', 12, 2)->nullable();
            $table->decimal('total_cost_of_ownership', 12, 2)->nullable();
            $table->float('roi_score')->nullable()->comment('Replacement ROI indicator');
            $table->float('confidence_score')->default(0);
            $table->boolean('is_approved')->default(false)->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->index(['asset_id', 'is_approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_replacement_predictions');
    }
}
