<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work captured on a phone without signal and sent later (spec Module K).
 *
 * Each queued change carries an id the phone made up when it was captured. The server applies a
 * change once and remembers the outcome under that id, so a phone that sends the same queue twice —
 * a dropped connection halfway through a sync is the normal case in the field — changes nothing the
 * second time and gets the same answer back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_offline_ops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->uuid('client_id');
            // task.start | task.submit | activity.start | activity.complete | observation.create
            $table->string('op', 30);
            $table->jsonb('payload');
            $table->timestamp('captured_at')->nullable(); // when the person did it, by the phone's clock
            $table->string('status', 12); // applied | rejected
            $table->string('message', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'client_id']);
        });
        AgriSchema::tenantRls(['agri_offline_ops']);
    }

    public function down(): void
    {
        Schema::dropIfExists('agri_offline_ops');
    }
};
