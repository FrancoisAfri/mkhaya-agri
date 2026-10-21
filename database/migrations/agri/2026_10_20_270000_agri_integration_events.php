<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §14: every message in or out of an integration — a clock event, a sensor reading, a
 * telematics fix, a weather fetch, an accounting export — kept with its idempotency key, status,
 * attempts and error. Failed messages wait in the queue for an administrator to retry, and a retry
 * of something already processed does nothing twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_integration_events', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->string('integration', 30);                                   // clock_device | sensor_gateway | telematics | weather | finance_export | …
            $t->string('direction', 3)->default('in');                       // in | out
            $t->unsignedBigInteger('device_id')->nullable();
            $t->string('idempotency_key', 160);
            $t->json('payload');
            $t->string('status', 10)->default('received');                   // received | processed | failed | dead
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedInteger('replays')->default(0);                       // the same message sent again, answered from the first
            $t->string('error', 1000)->nullable();
            $t->json('result')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->unsignedBigInteger('retried_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'integration', 'idempotency_key']);
            $t->index(['company_id', 'status']);
        });
        Schema::table('farm_machines', function (Blueprint $t) {
            $t->decimal('last_latitude', 10, 7)->nullable();
            $t->decimal('last_longitude', 10, 7)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->string('telematics_ref', 80)->nullable();                    // what the telematics provider calls this machine
        });
        AgriSchema::tenantRls(['agri_integration_events']);
    }

    public function down(): void
    {
        Schema::table('farm_machines', fn (Blueprint $t) => $t->dropColumn(['last_latitude', 'last_longitude', 'last_seen_at', 'telematics_ref']));
        Schema::dropIfExists('agri_integration_events');
    }
};
