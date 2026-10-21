<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §10: the farm assistant as decision support.
 *
 *  - agri_predictions: every prediction a model makes is kept with its basis, confidence and the
 *    date of the data behind it, and scored against what actually happened (§10.2);
 *  - agri_ai_drafts: what the assistant proposes — a work order, a requisition, an inspection, a
 *    report — waits here until a person with the right permission turns it into a real draft (§10.3);
 *  - agri_references: the approved agronomic references, farm procedures and regulations advice
 *    may draw on, and nothing else (§10.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_predictions', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->string('model', 30);                                         // yield | harvest_ready | stock_out | machine_breakdown | labour_demand
            $t->string('subject_type', 30);
            $t->unsignedBigInteger('subject_id');
            $t->date('predicted_on');
            $t->date('target_date')->nullable();                             // when the outcome is known
            $t->decimal('predicted_value', 16, 4)->nullable();
            $t->date('predicted_date')->nullable();
            $t->string('unit', 16)->nullable();
            $t->string('confidence', 10);                                    // low | medium | high
            $t->json('basis');
            $t->date('data_as_of');
            $t->decimal('actual_value', 16, 4)->nullable();
            $t->date('actual_date')->nullable();
            $t->decimal('error', 16, 4)->nullable();                         // actual − predicted (days for dates)
            $t->timestamp('scored_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'model', 'subject_type', 'subject_id', 'predicted_on']);
        });

        Schema::create('agri_ai_drafts', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->string('kind', 24);                                          // work_order | requisition | inspection | report
            $t->string('title', 200);
            $t->json('payload');
            $t->string('reason', 500);                                       // why the assistant proposes it, from which records
            $t->string('status', 10)->default('proposed');                   // proposed | accepted | discarded
            $t->string('result_type', 40)->nullable();
            $t->unsignedBigInteger('result_id')->nullable();
            $t->unsignedBigInteger('created_for')->nullable();               // whose question produced it
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('agri_references', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('title', 200);
            $t->string('kind', 12);                                          // agronomic | procedure | regulation
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->json('stages')->nullable();                                  // growth stages it applies to; null: any
            $t->string('region', 80)->nullable();
            $t->json('topics')->nullable();                                  // words it is found by: rust, nitrogen, planting date…
            $t->text('content');
            $t->string('source', 200);                                       // publisher, edition
            $t->string('status', 10)->default('draft');                      // draft | approved | retired
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->date('review_by')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        AgriSchema::tenantRls(['agri_predictions', 'agri_ai_drafts', 'agri_references']);
    }

    public function down(): void
    {
        foreach (['agri_references', 'agri_ai_drafts', 'agri_predictions'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
