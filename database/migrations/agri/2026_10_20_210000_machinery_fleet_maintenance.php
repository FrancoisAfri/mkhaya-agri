<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §7: the equipment register in full (§7.1); booking requests and their approval (§7.2);
 * pre-start checks, faults, repair work orders, downtime, return to service and warranty claims
 * (§7.3); fuel issued from the farm's tanks, odometer readings and trips (§7.4). Documents are
 * kept in the database like every other file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_machines', function (Blueprint $t) {
            $t->string('status', 20)->default('available')->change();       // available | maintenance | out_of_service | disposed
            $t->string('ownership', 12)->default('owned');                 // owned | leased | hired | shared
            $t->string('lessor', 160)->nullable();
            $t->date('lease_ends_on')->nullable();
            $t->decimal('lease_payment', 14, 2)->nullable();
            $t->date('warranty_until')->nullable();
            $t->decimal('warranty_hours', 10, 1)->nullable();
            $t->string('warranty_provider', 160)->nullable();
            $t->unsignedSmallInteger('useful_life_years')->nullable();
            $t->decimal('useful_life_hours', 10, 1)->nullable();
            $t->string('location', 160)->nullable();
            $t->unsignedBigInteger('responsible_user_id')->nullable();
            $t->decimal('odometer_km', 12, 1)->nullable();
            $t->unsignedInteger('cycles')->nullable();                      // bales, loads, starts — what the machine counts
            $t->date('insurance_expires_on')->nullable();
            $t->date('licence_expires_on')->nullable();
            $t->date('inspection_expires_on')->nullable();
            $t->boolean('inspection_required')->default(false);            // may not be booked once the inspection lapses
            $t->decimal('expected_litres_per_hour', 8, 2)->nullable();
            $t->unsignedSmallInteger('service_interval_days')->nullable();
            $t->string('out_of_service_reason', 300)->nullable();
        });
        DB::table('farm_machines')->where('status', 'active')->update(['status' => 'available']);

        Schema::table('farm_work_orders', fn (Blueprint $t) => $t->unsignedBigInteger('machine_id')->nullable()->index());   // the asset a repair job is on
        Schema::table('work_order_machines', function (Blueprint $t) {
            $t->string('status', 12)->default('approved');                 // requested | approved | rejected | released
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('operator_id')->nullable();
            $t->string('conflict', 300)->nullable();                        // why the booking is no longer safe to rely on
        });

        Schema::create('machine_documents', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('machine_id')->constrained('farm_machines')->cascadeOnDelete();
            $t->string('kind', 20);                                         // manual | warranty | insurance | licence | inspection | invoice | photo | other
            $t->string('name', 200);
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size')->nullable();
            $t->longText('content');                                        // base64
            $t->date('expires_on')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('machine_prestart_checks', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('machine_id')->constrained('farm_machines')->cascadeOnDelete();
            $t->date('checked_on');
            $t->unsignedBigInteger('operator_id')->nullable();
            $t->decimal('hours_reading', 10, 1)->nullable();
            $t->decimal('odometer_km', 12, 1)->nullable();
            $t->json('items');                                              // [{item, ok, critical, note}]
            $t->boolean('passed');
            $t->unsignedBigInteger('fault_id')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->unique(['machine_id', 'checked_on', 'operator_id']);
        });

        Schema::create('machine_faults', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('machine_id')->constrained('farm_machines')->cascadeOnDelete();
            $t->string('severity', 10);                                     // minor | major | critical
            $t->string('description', 1000);
            $t->string('source', 20)->default('report');                    // report | prestart | work_order | inspection
            $t->boolean('stops_machine')->default(false);
            $t->string('status', 16)->default('open');                      // open | in_repair | awaiting_return | closed
            $t->unsignedBigInteger('work_order_id')->nullable();            // the repair job
            $t->timestamp('down_from')->nullable();
            $t->timestamp('down_to')->nullable();
            $t->unsignedBigInteger('reported_by')->nullable();
            $t->unsignedBigInteger('returned_by')->nullable();              // who approved the return to service
            $t->string('return_note', 300)->nullable();
            $t->json('affected_work_orders')->nullable();                   // what the breakdown touched, as it stood when reported
            $t->timestamps();
        });

        Schema::create('machine_warranty_claims', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('machine_id')->constrained('farm_machines')->cascadeOnDelete();
            $t->unsignedBigInteger('fault_id')->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('provider', 160)->nullable();
            $t->date('claimed_on');
            $t->decimal('amount', 14, 2)->default(0);
            $t->string('reference', 60)->nullable();
            $t->string('status', 12)->default('submitted');                 // submitted | accepted | rejected | settled
            $t->decimal('recovered', 14, 2)->nullable();
            $t->string('outcome', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::table('machine_usage_logs', function (Blueprint $t) {
            $t->string('kind', 8)->default('use');                          // use | fuel | trip
            $t->decimal('odometer_after', 12, 1)->nullable();
            $t->decimal('distance_km', 10, 1)->nullable();
            $t->string('trip_from', 120)->nullable();
            $t->string('trip_to', 120)->nullable();
            $t->string('purpose', 200)->nullable();
            $t->unsignedBigInteger('stock_movement_id')->nullable();        // fuel issued from a farm tank
            $t->unsignedBigInteger('warehouse_id')->nullable();
        });

        AgriSchema::tenantRls(['machine_documents', 'machine_prestart_checks', 'machine_faults', 'machine_warranty_claims']);
    }

    public function down(): void
    {
        foreach (['machine_warranty_claims', 'machine_faults', 'machine_prestart_checks', 'machine_documents'] as $x) {
            Schema::dropIfExists($x);
        }
        Schema::table('machine_usage_logs', fn (Blueprint $t) => $t->dropColumn(['kind', 'odometer_after', 'distance_km', 'trip_from', 'trip_to', 'purpose', 'stock_movement_id', 'warehouse_id']));
        Schema::table('work_order_machines', fn (Blueprint $t) => $t->dropColumn(['status', 'requested_by', 'approved_by', 'approved_at', 'operator_id', 'conflict']));
        Schema::table('farm_work_orders', fn (Blueprint $t) => $t->dropColumn('machine_id'));
        DB::table('farm_machines')->where('status', 'available')->update(['status' => 'active']);
        Schema::table('farm_machines', fn (Blueprint $t) => $t->dropColumn(['ownership', 'lessor', 'lease_ends_on', 'lease_payment', 'warranty_until', 'warranty_hours',
            'warranty_provider', 'useful_life_years', 'useful_life_hours', 'location', 'responsible_user_id', 'odometer_km', 'cycles', 'insurance_expires_on',
            'licence_expires_on', 'inspection_expires_on', 'inspection_required', 'expected_litres_per_hour', 'service_interval_days', 'out_of_service_reason']));
    }
};
