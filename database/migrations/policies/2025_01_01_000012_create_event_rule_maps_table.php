<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_rule_maps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id')->index();
            $table->string('module');                        // which module this rule listens to
            $table->string('action_pattern');                // exact or wildcard: 'claim.*', 'leave_request.submitted'
            $table->json('payload_filter')->nullable();      // additional filter on the payload
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('rule_id')->references('id')->on('policy_rules')->onDelete('cascade');
            $table->index(['module', 'action_pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rule_maps');
    }
};
