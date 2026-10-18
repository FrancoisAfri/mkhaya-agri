<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authority-side registry of issued licenses. Lives in the product schema but is
 * only used on the install configured as the licensing authority
 * (LICENSE_AUTHORITY=true). `revoked` is the kill-switch each client heartbeats
 * against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_registry', function (Blueprint $table) {
            $table->id();
            $table->string('client');
            $table->string('domain')->nullable();
            $table->string('fingerprint')->unique();
            $table->boolean('revoked')->default(false);
            $table->string('revoked_reason')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_registry');
    }
};
