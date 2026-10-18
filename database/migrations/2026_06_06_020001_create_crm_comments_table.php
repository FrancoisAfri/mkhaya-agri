<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — team collaboration: threaded comments / discussions on any record
 * (spec section 10), with @mentions that fan out via CrmNotifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('entity_type', 20);            // lead|contact|account|deal|object
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_label')->nullable();   // denormalised display label
            $table->foreignId('parent_id')->nullable()->constrained('crm_comments')->cascadeOnDelete();
            $table->text('body');
            $table->json('mentioned')->nullable();        // [user_id,...]
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_comments');
    }
};
