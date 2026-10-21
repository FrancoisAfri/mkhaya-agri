<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §16B: vegetation indices from satellite or drone imagery, per field and capture, with
 * the change from the capture before; drops become flags to inspect, each checked in the field and
 * recorded as confirmed or a false alarm, so the imagery's own track record is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_field_imagery', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('field_id');
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->date('captured_on');
            $t->string('source', 60);                                        // Sentinel-2, a drone survey, a provider…
            $t->string('index', 10);                                         // ndvi | ndre | evi | savi | ndwi
            $t->decimal('mean', 6, 4);
            $t->decimal('min', 6, 4)->nullable();
            $t->decimal('max', 6, 4)->nullable();
            $t->decimal('cloud_pct', 5, 2)->nullable();
            $t->json('cells')->nullable();                                   // [{id, lat, lng, value}] zones of the field
            $t->string('image_mime', 60)->nullable();
            $t->longText('image_data')->nullable();                          // a rendered map, base64
            $t->decimal('change_pct', 7, 2)->nullable();                     // the mean against the previous capture
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->string('client_id', 120)->nullable();
            $t->timestamps();
            $t->unique(['field_id', 'index', 'captured_on', 'source']);
        });
        Schema::create('agri_imagery_flags', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('imagery_id');
            $t->unsignedBigInteger('field_id');
            $t->string('reason', 300);
            $t->json('cells')->nullable();                                   // where to look
            $t->unsignedBigInteger('observation_id')->nullable();            // the scouting task to check it
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->string('validation', 12)->default('pending');                // pending | confirmed | false_alarm
            $t->string('validation_note', 500)->nullable();
            $t->unsignedBigInteger('validated_by')->nullable();
            $t->timestamp('validated_at')->nullable();
            $t->timestamps();
        });
        AgriSchema::tenantRls(['agri_field_imagery', 'agri_imagery_flags']);
    }

    public function down(): void
    {
        Schema::dropIfExists('agri_imagery_flags');
        Schema::dropIfExists('agri_field_imagery');
    }
};
