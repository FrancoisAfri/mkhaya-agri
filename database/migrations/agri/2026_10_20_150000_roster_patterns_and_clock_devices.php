<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §1.2 and §1.3: roster patterns kept for a season or production cycle, and the clock
 * devices (biometric readers, access-control gates, kiosks) allowed to send attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_patterns', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('name', 120);
            $t->string('season', 60)->nullable();                 // "Citrus harvest", "Winter", "Lambing"…
            $t->jsonb('cycle');                                    // template codes, OFF for a rest day
            $t->unsignedBigInteger('crew_id')->nullable();         // the whole crew rotates…
            $t->jsonb('workers')->nullable();                      // …or these [{id, offset}]
            $t->unsignedBigInteger('field_id')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('farm_clock_devices', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('name', 120);
            $t->string('kind', 16);                                // biometric | access_control | kiosk
            $t->string('token_hash', 64)->unique();                // sha256 of the device's secret; the secret is shown once
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_seen_at')->nullable();
            $t->unsignedInteger('events_received')->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        AgriSchema::tenantRls(['roster_patterns', 'farm_clock_devices']);
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_clock_devices');
        Schema::dropIfExists('roster_patterns');
    }
};
