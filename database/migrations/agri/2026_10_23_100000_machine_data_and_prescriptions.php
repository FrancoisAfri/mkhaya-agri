<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things built together, because one feeds the other:
 *
 *  A. Machinery data connectors for John Deere Operations Center and CNH FieldOps (Case IH / New
 *     Holland): one OAuth connection per organisation and platform with its tokens encrypted
 *     (agri_machine_connections); every field, machine, product, prescription and boundary paired
 *     with its id on the other side (agri_machine_links); each field operation imported with its
 *     raw payload kept for audit and what it became here (agri_machine_operations); and a boundary
 *     changed on both sides held for a person to decide (agri_machine_conflicts).
 *  B. Variable-rate prescriptions: a rate per management zone (or per NDVI class) for one product
 *     on one field (agri_prescriptions, agri_prescription_zones), drafted by one person and approved
 *     by another, and the machine files made from it — zipped shapefile, ISO-XML, GeoJSON — kept
 *     in the database, base64, by version (agri_prescription_files).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── A ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_machine_connections', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('provider', 12);                                  // deere | cnh
            $t->string('status', 24)->default('disconnected');           // needs_registration | disconnected | choose_organisation | needs_org_access | connected | error
            $t->string('org_id', 120)->nullable();                       // Deere organisation / CNH company
            $t->string('org_name', 200)->nullable();
            $t->jsonb('organisations')->nullable();                      // offered after connecting [{id, name, needs_access, access_url}]
            $t->text('tokens')->nullable();                              // encrypted {access_token, refresh_token, expires_at, scope}
            $t->string('oauth_state', 64)->nullable();
            $t->timestamp('oauth_state_expires_at')->nullable();
            $t->jsonb('settings')->nullable();                           // {import_boundaries, import_machines, import_telemetry, import_operations, push_boundaries, complete_imported}
            $t->jsonb('cursors')->nullable();                            // {operations_since}
            $t->timestamp('connected_at')->nullable();
            $t->unsignedBigInteger('connected_by')->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->jsonb('last_sync')->nullable();                          // the counts of the last run
            $t->string('last_error', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'provider']);
        });

        Schema::create('agri_machine_links', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('connection_id')->constrained('agri_machine_connections')->cascadeOnDelete();
            $t->string('kind', 14);                                      // field | machine | product | prescription | boundary
            $t->string('external_id', 160);
            $t->string('external_name', 200)->nullable();
            $t->unsignedBigInteger('local_id')->nullable();              // farm_fields / fleet_vehicles / fin_stock_items / agri_prescriptions
            $t->string('status', 14)->default('linked');                 // linked | unmatched | ignored | pending | sent | failed
            $t->string('matched_by', 30)->nullable();                    // serial | vin | name | code | person | created
            $t->string('external_hash', 64)->nullable();                 // their boundary as last seen
            $t->string('local_hash', 64)->nullable();                    // ours as it was when the two last agreed
            $t->jsonb('data')->nullable();                               // what they told us (name, serial, model, boundary, area)
            $t->unsignedBigInteger('event_id')->nullable();              // the integration message that carries a push
            $t->unsignedInteger('attempts')->default(0);
            $t->string('remote_status', 60)->nullable();                 // the platform's own word for a sent file
            $t->string('last_error', 1000)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'kind', 'external_id']);
            $t->index(['connection_id', 'kind', 'local_id']);
            $t->index(['company_id', 'kind', 'status']);
        });

        Schema::create('agri_machine_operations', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('connection_id')->constrained('agri_machine_connections')->cascadeOnDelete();
            $t->string('external_id', 160);
            $t->string('operation_type', 14);                            // application | seeding | harvest
            $t->string('external_field_id', 160)->nullable();
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('machine_id')->nullable();
            $t->string('external_machine_id', 160)->nullable();
            $t->string('operator', 160)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->decimal('area_ha', 14, 4)->nullable();
            $t->jsonb('products')->nullable();                           // [{name, rate, unit, total, total_unit, area_ha, stock_item_id}]
            $t->decimal('total', 16, 4)->nullable();                     // harvest: total yield
            $t->string('total_unit', 20)->nullable();
            $t->decimal('yield_per_ha', 14, 4)->nullable();
            $t->decimal('moisture_pct', 6, 2)->nullable();
            $t->jsonb('zones')->nullable();                              // yield or rate by zone, when the data allows
            $t->jsonb('rate_points')->nullable();                        // as-applied rate samples [{lng, lat, rate}]
            $t->string('status', 16)->default('imported');               // imported | applied | needs_field | needs_product | failed
            $t->string('note', 500)->nullable();
            $t->unsignedBigInteger('activity_id')->nullable();           // the field activity it created or filled
            $t->unsignedBigInteger('harvest_record_id')->nullable();
            $t->unsignedBigInteger('prescription_id')->nullable();       // the prescription it carried out
            $t->string('payload_hash', 64)->nullable();
            $t->jsonb('raw');                                            // what the platform sent, kept for audit
            $t->unsignedBigInteger('event_id')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'external_id']);
            $t->index(['company_id', 'field_id', 'operation_type']);
        });

        Schema::create('agri_machine_conflicts', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('connection_id')->constrained('agri_machine_connections')->cascadeOnDelete();
            $t->unsignedBigInteger('link_id');
            $t->string('kind', 14)->default('boundary');
            $t->unsignedBigInteger('field_id')->nullable();
            $t->jsonb('ours')->nullable();
            $t->jsonb('theirs')->nullable();
            $t->decimal('ours_ha', 14, 4)->nullable();
            $t->decimal('theirs_ha', 14, 4)->nullable();
            $t->string('status', 14)->default('open');                   // open | kept_ours | took_theirs
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['connection_id', 'status']);
        });

        // ── B ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_prescriptions', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $t->string('code', 30);
            $t->string('name', 160);
            $t->string('operation', 16);                                 // seeding | fertiliser | lime | crop_protection
            $t->unsignedBigInteger('stock_item_id')->nullable();
            $t->string('product_name', 200);
            $t->string('unit', 10);                                      // kg_ha | l_ha | seeds_ha
            $t->decimal('per_stock_unit', 14, 4)->default(1);            // how many of the rate's units one stock unit holds (80 000 seeds a bag)
            $t->decimal('unit_cost', 14, 4)->default(0);                 // per stock unit, from the stock item
            $t->string('source', 8);                                     // zones | ndvi
            $t->unsignedBigInteger('imagery_id')->nullable();            // the NDVI capture classified
            $t->unsignedTinyInteger('classes')->nullable();
            $t->string('method', 16)->nullable();                        // equal_interval | quantile
            $t->boolean('flip')->default(false);                         // false: more on the stronger zones; true: more on the weaker
            $t->decimal('base_rate', 14, 4);
            $t->decimal('step_pct', 6, 2)->default(0);
            $t->decimal('flat_rate', 14, 4)->nullable();                 // what a single rate would be, for the comparison
            $t->decimal('area_ha', 14, 4)->default(0);
            $t->decimal('total_quantity', 16, 4)->default(0);            // in the rate's unit (kg, l, seeds)
            $t->decimal('total_cost', 16, 2)->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 12)->default('draft');                  // draft | submitted | approved | sent | applied | cancelled
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedInteger('approved_version')->nullable();
            $t->string('rejection_note', 500)->nullable();
            $t->unsignedBigInteger('activity_id')->nullable();           // the planned field activity
            $t->date('planned_on')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->index(['farm_id', 'field_id', 'status']);
        });

        Schema::create('agri_prescription_zones', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('prescription_id')->constrained('agri_prescriptions')->cascadeOnDelete();
            $t->unsignedBigInteger('zone_id')->nullable();               // the field zone, when it came from one
            $t->unsignedSmallInteger('position');
            $t->string('label', 120);
            $t->unsignedTinyInteger('class')->nullable();                // 1 weakest … N strongest
            $t->string('zone_class', 20)->nullable();                    // the field zone's own class
            $t->decimal('ndvi', 6, 4)->nullable();
            $t->jsonb('boundary');
            $t->decimal('area_ha', 14, 4);
            $t->decimal('rate', 14, 4);
            $t->decimal('quantity', 16, 4);
            $t->decimal('cost', 16, 2);
            $t->timestamps();
            $t->index(['prescription_id', 'position']);
        });

        Schema::create('agri_prescription_files', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('prescription_id')->constrained('agri_prescriptions')->cascadeOnDelete();
            $t->string('format', 10);                                    // shapefile | isoxml | geojson
            $t->unsignedInteger('version');
            $t->string('file_name', 200);
            $t->string('mime', 80);
            $t->longText('data');                                        // the file, base64, as every file is kept
            $t->unsignedInteger('size');
            $t->string('sha256', 64);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['prescription_id', 'format', 'version']);
        });

        AgriSchema::tenantRls(['agri_machine_connections', 'agri_machine_links', 'agri_machine_operations', 'agri_machine_conflicts',
            'agri_prescriptions', 'agri_prescription_zones', 'agri_prescription_files']);
    }

    public function down(): void
    {
        foreach (['agri_prescription_files', 'agri_prescription_zones', 'agri_prescriptions', 'agri_machine_conflicts', 'agri_machine_operations',
            'agri_machine_links', 'agri_machine_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
