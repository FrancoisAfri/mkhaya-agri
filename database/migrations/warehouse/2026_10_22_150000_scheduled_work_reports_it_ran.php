<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-212: each scheduled warehouse command leaves a heartbeat, so a scheduler or queue that has
 * quietly stopped is noticed. Platform-wide by design: there is no company column, so tenant row
 * security does not apply and every company's health page reads the same beat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_heartbeats', function (Blueprint $t) {
            $t->string('command', 60)->primary();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->boolean('ok')->nullable();
            $t->unsignedInteger('runs')->default(0);
            $t->unsignedInteger('failures')->default(0);
            $t->jsonb('details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_heartbeats');
    }
};
