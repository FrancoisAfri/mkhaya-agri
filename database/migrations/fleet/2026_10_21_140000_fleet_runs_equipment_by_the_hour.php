<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet runs equipment as well as road vehicles.
 *
 * A tractor, harvester, sprayer, pump or generator is a fleet asset like any vehicle, but it may have
 * no number plate, it is measured in engine hours rather than kilometres, it is serviced every so
 * many hours or days, it is charged out to jobs by the hour, and it can be stopped by a fault for a
 * stretch of downtime. So a fleet vehicle gains: an asset class (vehicle | equipment), an optional
 * registration, a name and serial number, hours-based service intervals, an hourly charge-out rate,
 * ownership/lease, warranty, useful life, statutory inspection, licence and insurance dates, expected
 * fuel per hour, telematics position — and, when Mkhaya Agri is used, the farm it works on.
 *
 * And Fleet keeps, for every asset: a usage log (hours, distance, fuel, trips, what the use was for),
 * faults with their downtime and the job that repairs them, daily pre-start checks, and warranty
 * claims. Services are fleet maintenance records, which gain the hours at service and the journal
 * they were posted in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_vehicles', function (Blueprint $t) {
            $t->string('registration_number', 20)->nullable()->change();     // equipment often has none
            $t->string('asset_class', 12)->default('vehicle');                 // vehicle | equipment
            $t->string('name', 160)->nullable();
            $t->string('serial_number', 80)->nullable();
            $t->unsignedBigInteger('farm_id')->nullable()->index();            // Mkhaya Agri: the farm it works on
            $t->unsignedBigInteger('legacy_farm_machine_id')->nullable();      // the farm machine it was, where its id changed
            $t->decimal('service_interval_hours', 10, 1)->nullable();
            $t->decimal('next_service_hours', 10, 1)->nullable();
            $t->unsignedInteger('service_interval_days')->nullable();
            $t->date('next_service_on')->nullable();
            $t->decimal('hourly_rate', 12, 2)->nullable();                     // charged out to jobs per hour
            $t->decimal('disposal_value', 14, 2)->nullable();
            $t->string('ownership', 10)->default('owned');                     // owned | leased | hired | shared
            $t->string('lessor', 160)->nullable();
            $t->date('lease_ends_on')->nullable();
            $t->decimal('lease_payment', 12, 2)->nullable();
            $t->date('warranty_until')->nullable();
            $t->decimal('warranty_hours', 10, 1)->nullable();
            $t->string('warranty_provider', 160)->nullable();
            $t->unsignedSmallInteger('useful_life_years')->nullable();
            $t->decimal('useful_life_hours', 10, 1)->nullable();
            $t->unsignedBigInteger('responsible_user_id')->nullable();
            $t->unsignedInteger('cycles')->nullable();
            $t->date('insurance_expires_on')->nullable();
            $t->date('licence_expires_on')->nullable();
            $t->date('inspection_expires_on')->nullable();
            $t->boolean('inspection_required')->default(false);
            $t->decimal('expected_litres_per_hour', 8, 2)->nullable();
            $t->string('out_of_service_reason', 500)->nullable();
            $t->string('telematics_ref', 80)->nullable();
            $t->decimal('last_latitude', 10, 7)->nullable();
            $t->decimal('last_longitude', 10, 7)->nullable();
            $t->timestampTz('last_seen_at')->nullable();
        });

        Schema::table('fleet_maintenance', function (Blueprint $t) {
            $t->decimal('hours_at_service', 10, 1)->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('farm_id')->nullable()->index();
        });

        Schema::create('fleet_usage_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('vehicle_id')->index();
            $t->unsignedBigInteger('farm_id')->nullable()->index();
            $t->string('kind', 8)->default('use');                               // use | fuel | trip
            $t->date('used_on');
            $t->decimal('hours', 10, 2)->nullable();
            $t->decimal('hours_reading_after', 10, 1)->nullable();
            $t->decimal('odometer_after', 12, 1)->nullable();
            $t->decimal('distance_km', 10, 1)->nullable();
            $t->decimal('fuel_litres', 10, 2)->nullable();
            $t->decimal('fuel_cost', 12, 2)->nullable();
            $t->unsignedBigInteger('operator_id')->nullable();
            $t->string('source_type', 40)->nullable();                           // what the use was for, e.g. agri_activity
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('trip_from', 160)->nullable();
            $t->string('trip_to', 160)->nullable();
            $t->string('purpose', 300)->nullable();
            $t->unsignedBigInteger('stock_movement_id')->nullable();             // fuel issued from a tank
            $t->unsignedBigInteger('warehouse_id')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('fleet_faults', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('vehicle_id')->index();
            $t->unsignedBigInteger('farm_id')->nullable()->index();
            $t->string('severity', 10);                                          // minor | major | critical
            $t->string('description', 1000);
            $t->string('source', 12)->default('report');                         // report | prestart | telematics | job
            $t->boolean('stops_machine')->default(false);
            $t->string('status', 16)->default('open');                           // open | in_repair | awaiting_return | resolved
            $t->unsignedBigInteger('work_order_id')->nullable();                 // the job repairing it (a farm work order)
            $t->timestamp('down_from')->nullable();
            $t->timestamp('down_to')->nullable();
            $t->unsignedBigInteger('reported_by')->nullable();
            $t->unsignedBigInteger('returned_by')->nullable();
            $t->string('return_note', 1000)->nullable();
            $t->json('affected_work_orders')->nullable();
            $t->timestamps();
        });

        Schema::create('fleet_prestart_checks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('vehicle_id')->index();
            $t->unsignedBigInteger('farm_id')->nullable()->index();
            $t->date('checked_on');
            $t->unsignedBigInteger('operator_id')->nullable();
            $t->decimal('hours_reading', 10, 1)->nullable();
            $t->decimal('odometer_km', 12, 1)->nullable();
            $t->json('items');
            $t->boolean('passed');
            $t->unsignedBigInteger('fault_id')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('fleet_warranty_claims', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('vehicle_id')->index();
            $t->unsignedBigInteger('farm_id')->nullable()->index();
            $t->unsignedBigInteger('fault_id')->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('provider', 160)->nullable();
            $t->date('claimed_on');
            $t->decimal('amount', 14, 2)->nullable();
            $t->string('reference', 80)->nullable();
            $t->string('status', 12)->default('submitted');                      // submitted | accepted | rejected | paid
            $t->decimal('recovered', 14, 2)->nullable();
            $t->string('outcome', 1000)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (['fleet_usage_logs', 'fleet_faults', 'fleet_prestart_checks', 'fleet_warranty_claims'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        foreach (['fleet_warranty_claims', 'fleet_prestart_checks', 'fleet_faults', 'fleet_usage_logs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('fleet_maintenance', fn (Blueprint $t) => $t->dropColumn(['hours_at_service', 'journal_id', 'farm_id']));
        Schema::table('fleet_vehicles', fn (Blueprint $t) => $t->dropColumn(['asset_class', 'name', 'serial_number', 'farm_id', 'legacy_farm_machine_id', 'service_interval_hours',
            'next_service_hours', 'service_interval_days', 'next_service_on', 'hourly_rate', 'disposal_value', 'ownership', 'lessor', 'lease_ends_on', 'lease_payment',
            'warranty_until', 'warranty_hours', 'warranty_provider', 'useful_life_years', 'useful_life_hours', 'responsible_user_id', 'cycles', 'insurance_expires_on',
            'licence_expires_on', 'inspection_expires_on', 'inspection_required', 'expected_litres_per_hour', 'out_of_service_reason', 'telematics_ref', 'last_latitude',
            'last_longitude', 'last_seen_at']));
    }
};
