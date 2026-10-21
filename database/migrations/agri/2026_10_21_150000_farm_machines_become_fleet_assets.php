<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Farm machines become Fleet assets.
 *
 * Each farm machine is copied into fleet_vehicles — as equipment (or a vehicle, for a bakkie),
 * keeping its uuid, its farm and, where that id is free in Fleet, its id, so every farm record and
 * ledger dimension that names it still names it. Where the id is taken the asset gets a new one,
 * remembers the old one (legacy_farm_machine_id), and the farm's own records are re-pointed. Its
 * usage, faults, pre-start checks, warranty claims, services and documents are copied into Fleet's
 * tables. Nothing is deleted from the farm tables, and an asset already moved (same uuid) is skipped.
 *
 * The farm tables' foreign keys to farm_machines are dropped: a machine id now names a Fleet asset.
 */
return new class extends Migration
{
    private const FARM_COLUMNS = [['field_activities', 'machine_id'], ['work_order_machines', 'machine_id'], ['farm_work_orders', 'machine_id'], ['work_order_logs', 'machine_id'],
        ['irrigation_zones', 'pump_machine_id'], ['packhouse_operations', 'machine_id'], ['produce_dispatches', 'machine_id'], ['farm_tasks', 'machine_id'], ['farm_budget_lines', 'machine_id']];

    private const STATUS = ['available' => 'available', 'active' => 'available', 'maintenance' => 'maintenance', 'out_of_service' => 'offroad', 'disposed' => 'disposed'];

    public function up(): void
    {
        foreach (['machine_usage_logs', 'machine_services', 'field_activities', 'work_order_machines', 'machine_documents', 'machine_prestart_checks', 'machine_faults', 'machine_warranty_claims'] as $t) {
            if (Schema::hasTable($t)) {
                DB::statement("ALTER TABLE {$t} DROP CONSTRAINT IF EXISTS {$t}_machine_id_foreign");
            }
        }
        if (! Schema::hasTable('farm_machines')) {
            return;
        }

        foreach (DB::table('farm_machines')->orderBy('id')->get() as $m) {
            if (DB::table('fleet_vehicles')->where('uuid', $m->uuid)->exists()) {
                continue;
            }
            DB::select("SELECT set_config('app.company_id', ?, false)", [(string) $m->company_id]);
            try {
                DB::transaction(fn () => $this->move($m));
            } finally {
                DB::select("SELECT set_config('app.company_id', '', false)");
            }
        }
        DB::statement("SELECT setval('fleet_vehicles_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM fleet_vehicles), 1))");
    }

    private function move(object $m): void
    {
        $sameId = ! DB::table('fleet_vehicles')->where('id', $m->id)->exists();
        $row = [
            'uuid' => $m->uuid, 'company_id' => $m->company_id, 'farm_id' => $m->farm_id, 'fleet_number' => $m->code, 'name' => $m->name,
            'asset_class' => $m->machine_type === 'vehicle' ? 'vehicle' : 'equipment', 'vehicle_type' => $m->machine_type, 'fuel_type' => 'diesel',
            'make' => $m->make, 'model' => $m->model, 'year' => $m->year, 'serial_number' => $m->serial_number, 'registration_number' => $m->registration,
            'hours_reading' => $m->hours_reading ?? 0, 'odometer_reading' => $m->odometer_km ?? 0, 'status' => self::STATUS[$m->status] ?? 'available',
            'purchase_price' => $m->purchase_cost, 'purchase_date' => $m->purchased_on, 'disposal_date' => $m->disposed_on, 'disposal_reason' => $m->disposal_reason,
            'disposal_value' => $m->disposal_value, 'location' => $m->location, 'notes' => $m->notes,
            'service_interval_hours' => $m->service_interval_hours, 'next_service_hours' => $m->next_service_hours, 'service_interval_days' => $m->service_interval_days,
            'next_service_on' => $m->next_service_on, 'hourly_rate' => $m->hourly_rate, 'ownership' => $m->ownership ?? 'owned', 'lessor' => $m->lessor,
            'lease_ends_on' => $m->lease_ends_on, 'lease_payment' => $m->lease_payment, 'warranty_until' => $m->warranty_until, 'warranty_hours' => $m->warranty_hours,
            'warranty_provider' => $m->warranty_provider, 'useful_life_years' => $m->useful_life_years, 'useful_life_hours' => $m->useful_life_hours,
            'responsible_user_id' => $m->responsible_user_id, 'cycles' => $m->cycles, 'insurance_expires_on' => $m->insurance_expires_on,
            'licence_expires_on' => $m->licence_expires_on, 'inspection_expires_on' => $m->inspection_expires_on, 'inspection_required' => (bool) $m->inspection_required,
            'expected_litres_per_hour' => $m->expected_litres_per_hour, 'out_of_service_reason' => $m->out_of_service_reason, 'telematics_ref' => $m->telematics_ref,
            'last_latitude' => $m->last_latitude, 'last_longitude' => $m->last_longitude, 'last_seen_at' => $m->last_seen_at,
            'legacy_farm_machine_id' => $sameId ? null : $m->id, 'created_at' => $m->created_at, 'updated_at' => $m->updated_at,
        ];
        $vid = $sameId ? (DB::table('fleet_vehicles')->insert($row + ['id' => $m->id]) ? $m->id : null) : DB::table('fleet_vehicles')->insertGetId($row);

        if ($vid !== (int) $m->id) {
            foreach (self::FARM_COLUMNS as [$table, $col]) {
                if (Schema::hasColumn($table, $col)) {
                    DB::table($table)->where($col, $m->id)->update([$col => $vid]);
                }
            }
        }

        $faults = [];
        if (Schema::hasTable('machine_faults')) {
            foreach (DB::table('machine_faults')->where('machine_id', $m->id)->orderBy('id')->get() as $f) {
                $faults[$f->id] = DB::table('fleet_faults')->insertGetId(['uuid' => $f->uuid, 'company_id' => $f->company_id, 'vehicle_id' => $vid, 'farm_id' => $f->farm_id,
                    'severity' => $f->severity, 'description' => $f->description, 'source' => $f->source, 'stops_machine' => $f->stops_machine, 'status' => $f->status,
                    'work_order_id' => $f->work_order_id, 'down_from' => $f->down_from, 'down_to' => $f->down_to, 'reported_by' => $f->reported_by, 'returned_by' => $f->returned_by,
                    'return_note' => $f->return_note, 'affected_work_orders' => $f->affected_work_orders, 'created_at' => $f->created_at, 'updated_at' => $f->updated_at]);
            }
        }
        if (Schema::hasTable('machine_usage_logs')) {
            foreach (DB::table('machine_usage_logs')->where('machine_id', $m->id)->orderBy('id')->get() as $u) {
                DB::table('fleet_usage_logs')->insert(['uuid' => $u->uuid, 'company_id' => $u->company_id, 'vehicle_id' => $vid, 'farm_id' => $u->farm_id, 'kind' => $u->kind ?? 'use',
                    'used_on' => $u->used_on, 'hours' => $u->hours, 'hours_reading_after' => $u->hours_reading_after, 'odometer_after' => $u->odometer_after, 'distance_km' => $u->distance_km,
                    'fuel_litres' => $u->fuel_litres, 'fuel_cost' => $u->fuel_cost, 'operator_id' => $u->operator_id, 'source_type' => $u->activity_id ? 'agri_activity' : null,
                    'source_id' => $u->activity_id, 'trip_from' => $u->trip_from, 'trip_to' => $u->trip_to, 'purpose' => $u->purpose, 'stock_movement_id' => $u->stock_movement_id,
                    'warehouse_id' => $u->warehouse_id, 'notes' => $u->notes, 'recorded_by' => $u->recorded_by, 'created_at' => $u->created_at, 'updated_at' => $u->updated_at]);
            }
        }
        if (Schema::hasTable('machine_prestart_checks')) {
            foreach (DB::table('machine_prestart_checks')->where('machine_id', $m->id)->orderBy('id')->get() as $p) {
                DB::table('fleet_prestart_checks')->insert(['uuid' => $p->uuid, 'company_id' => $p->company_id, 'vehicle_id' => $vid, 'farm_id' => $p->farm_id, 'checked_on' => $p->checked_on,
                    'operator_id' => $p->operator_id, 'hours_reading' => $p->hours_reading, 'odometer_km' => $p->odometer_km, 'items' => $p->items, 'passed' => $p->passed,
                    'fault_id' => $p->fault_id ? ($faults[$p->fault_id] ?? null) : null, 'recorded_by' => $p->recorded_by, 'created_at' => $p->created_at, 'updated_at' => $p->updated_at]);
            }
        }
        if (Schema::hasTable('machine_warranty_claims')) {
            foreach (DB::table('machine_warranty_claims')->where('machine_id', $m->id)->orderBy('id')->get() as $w) {
                DB::table('fleet_warranty_claims')->insert(['uuid' => $w->uuid, 'company_id' => $w->company_id, 'vehicle_id' => $vid, 'farm_id' => $w->farm_id,
                    'fault_id' => $w->fault_id ? ($faults[$w->fault_id] ?? null) : null, 'supplier_id' => $w->supplier_id, 'provider' => $w->provider, 'claimed_on' => $w->claimed_on,
                    'amount' => $w->amount, 'reference' => $w->reference, 'status' => $w->status, 'recovered' => $w->recovered, 'outcome' => $w->outcome, 'created_by' => $w->created_by,
                    'created_at' => $w->created_at, 'updated_at' => $w->updated_at]);
            }
        }
        if (Schema::hasTable('machine_services')) {
            foreach (DB::table('machine_services')->where('machine_id', $m->id)->orderBy('id')->get() as $s) {
                DB::table('fleet_maintenance')->insert(['uuid' => $s->uuid, 'company_id' => $s->company_id, 'vehicle_id' => $vid, 'farm_id' => $s->farm_id,
                    'maintenance_type' => $s->service_type ?: 'service', 'description' => $s->description, 'completed_date' => $s->serviced_on, 'service_provider' => $s->provider,
                    'cost' => $s->cost, 'hours_at_service' => $s->hours_at_service, 'journal_id' => $s->journal_id, 'status' => 'completed', 'created_by' => $s->recorded_by,
                    'created_at' => $s->created_at, 'updated_at' => $s->updated_at]);
            }
        }
        if (Schema::hasTable('machine_documents')) {
            foreach (DB::table('machine_documents')->where('machine_id', $m->id)->orderBy('id')->get() as $d) {
                DB::table('fleet_documents')->insert(['uuid' => $d->uuid, 'company_id' => $d->company_id, 'vehicle_id' => $vid, 'document_type' => $d->kind ?: 'other',
                    'document_number' => mb_strimwidth((string) $d->name, 0, 100), 'expiry_date' => $d->expires_on, 'status' => 'valid', 'created_by' => $d->uploaded_by,
                    'file_path' => json_encode(['uuid' => (string) Str::uuid(), 'original_name' => $d->name, 'mime' => $d->mime, 'type' => str_starts_with((string) $d->mime, 'image/') ? 'image' : 'document',
                        'data' => $d->content, 'size' => $d->size]), 'created_at' => $d->created_at, 'updated_at' => $d->updated_at]);
            }
        }
    }

    public function down(): void
    {
        // The Fleet assets stay; the farm machine rows were never changed.
    }
};
