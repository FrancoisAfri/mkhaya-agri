<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satellite crop health fetched by the farm itself: each organisation's imagery provider with its
 * credentials (encrypted), every acquisition looked at per field (stored, too cloudy or without
 * data — so the same scene is never asked for or stored twice), the polygons registered with a
 * provider that needs them, and the requests made per day against the organisation's budget.
 * The index values and the NDVI picture themselves go into agri_field_imagery, as every capture does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_satellite_providers', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('provider', 30);                          // copernicus | agromonitoring
            $t->boolean('is_active')->default(false);           // the one the daily fetch uses
            $t->text('credentials')->nullable();                // encrypted JSON
            $t->jsonb('settings')->nullable();                  // max cloud, daily budget, look-back, …
            $t->timestamp('last_test_at')->nullable();
            $t->boolean('last_test_ok')->nullable();
            $t->string('last_test_message', 500)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'provider']);
        });

        Schema::create('agri_satellite_scenes', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $t->string('provider', 30);
            $t->date('acquired_on');
            $t->string('scene_ref', 200)->nullable();
            $t->decimal('cloud_pct', 5, 2)->nullable();          // over the field, after masking
            $t->string('status', 20);                            // stored | too_cloudy | no_data
            $t->unsignedBigInteger('imagery_id')->nullable();    // the NDVI capture it became
            $t->jsonb('bounds')->nullable();                     // [minLng, minLat, maxLng, maxLat] the picture covers
            $t->unsignedInteger('zones')->default(0);
            $t->boolean('has_image')->default(false);
            $t->timestamps();
            $t->unique(['field_id', 'provider', 'acquired_on']);
            $t->index(['farm_id', 'acquired_on']);
        });

        Schema::create('agri_satellite_polygons', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $t->unsignedBigInteger('zone_id')->nullable();
            $t->string('provider', 30);
            $t->string('external_id', 100);
            $t->string('boundary_hash', 64);
            $t->timestamps();
            $t->index(['provider', 'field_id', 'zone_id']);
        });

        Schema::create('agri_satellite_usage', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->date('used_on');
            $t->unsignedInteger('requests')->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'used_on']);
        });

        AgriSchema::tenantRls(['agri_satellite_providers', 'agri_satellite_scenes', 'agri_satellite_polygons', 'agri_satellite_usage']);
    }

    public function down(): void
    {
        Schema::dropIfExists('agri_satellite_usage');
        Schema::dropIfExists('agri_satellite_polygons');
        Schema::dropIfExists('agri_satellite_scenes');
        Schema::dropIfExists('agri_satellite_providers');
    }
};
