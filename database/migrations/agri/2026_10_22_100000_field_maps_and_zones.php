<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field maps people draw, import and edit: the area each boundary measures, every earlier version
 * of a boundary (so the area a crop plan used can be audited), management zones inside a field,
 * and boundary files waiting to be matched to fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The geodesic area of the stored boundary, beside the area people recorded.
        Schema::table('farm_fields', fn (Blueprint $t) => $t->decimal('mapped_area_ha', 14, 4)->nullable());
        Schema::table('farms', fn (Blueprint $t) => $t->decimal('mapped_area_ha', 14, 4)->nullable());

        // One row per change to a field's (or, with no field, the farm's) boundary.
        Schema::create('field_boundary_versions', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->nullable()->constrained('farm_fields')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('source', 20);                         // drawn, edited, imported, removed, seeded
            $t->string('source_name', 200)->nullable();      // the imported file
            $t->jsonb('boundary')->nullable();
            $t->jsonb('previous_boundary')->nullable();
            $t->decimal('area_ha', 14, 4)->nullable();
            $t->decimal('previous_area_ha', 14, 4)->nullable();
            $t->decimal('recorded_area_ha', 14, 4)->nullable();
            $t->string('reason', 300)->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->timestamps();
            $t->index(['farm_id', 'field_id', 'version']);
        });

        // Management zones: named parts of one field that later carry NDVI statistics and variable rates.
        Schema::create('field_zones', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $t->string('name', 120);
            $t->string('zone_class', 20);
            $t->string('soil_type', 60)->nullable();
            $t->text('notes')->nullable();
            $t->jsonb('boundary');
            $t->decimal('area_ha', 14, 4);
            $t->string('status', 20)->default('active');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['field_id', 'status']);
        });

        // An uploaded boundary file, parsed and previewed before anything changes.
        Schema::create('field_map_imports', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('file_name', 200);
            $t->string('format', 20);                          // geojson, kml, kmz, shapefile
            $t->string('mime', 120)->nullable();
            $t->longText('data');                              // the file itself, base64, as every file is kept
            $t->string('crs_note', 300)->nullable();           // how its coordinates were read
            $t->jsonb('features');
            $t->string('status', 20)->default('previewed');    // previewed, applied, discarded
            $t->jsonb('summary')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('applied_by')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });

        AgriSchema::tenantRls(['field_boundary_versions', 'field_zones', 'field_map_imports']);
    }

    public function down(): void
    {
        Schema::dropIfExists('field_map_imports');
        Schema::dropIfExists('field_zones');
        Schema::dropIfExists('field_boundary_versions');
        Schema::table('farms', fn (Blueprint $t) => $t->dropColumn('mapped_area_ha'));
        Schema::table('farm_fields', fn (Blueprint $t) => $t->dropColumn('mapped_area_ha'));
    }
};
