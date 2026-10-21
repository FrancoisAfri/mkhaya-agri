<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §4: livestock as a business — ownership and quarantine (§4.1), breeding dates,
 * production and feeding (§4.2), vaccination programmes, notifiable disease and verified
 * clearance before sale (§4.3), and what a herd or batch costs per head and per unit (§4.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->string('ownership', 16)->default('owned');           // owned | leased | agisted | partnership | custody
            $t->string('owner_name', 160)->nullable();                // when the farm does not own it
            $t->boolean('birth_date_estimated')->default(false);
            $t->string('source', 160)->nullable();                    // breeder, auction, supplier
            $t->date('quarantine_until')->nullable();
            $t->string('exit_cause', 120)->nullable();                // mortality cause
        });
        Schema::table('livestock_groups', function (Blueprint $t) {
            $t->string('batch_code', 40)->nullable();                 // a production batch (broilers, a feedlot intake)
            $t->date('closed_on')->nullable();
            $t->unsignedInteger('target_head')->nullable();           // herd size to maintain, for replacement planning
            $t->date('quarantine_until')->nullable();
        });
        Schema::table('livestock_species', function (Blueprint $t) {
            $t->unsignedSmallInteger('productive_years')->nullable(); // breeding life, for replacement planning
            $t->unsignedSmallInteger('breeding_age_months')->nullable();
        });
        Schema::table('animal_events', function (Blueprint $t) {
            $t->date('expected_on')->nullable();                      // a pregnancy's expected birth
            $t->unsignedBigInteger('sire_id')->nullable();
            $t->string('method', 20)->nullable();                     // natural | ai | et
        });
        Schema::table('animal_health_records', function (Blueprint $t) {
            $t->boolean('notifiable')->default(false);                // a notifiable / controlled disease
            $t->unsignedSmallInteger('quarantine_days')->nullable();
            $t->boolean('requires_clearance')->default(false);        // sale waits for a verified release (e.g. residue test)
            $t->unsignedBigInteger('cleared_by')->nullable();
            $t->timestamp('cleared_at')->nullable();
            $t->string('clearance_note', 300)->nullable();
            $t->unsignedBigInteger('programme_id')->nullable();
        });

        Schema::create('livestock_production', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('animal_id')->nullable();
            $t->unsignedBigInteger('group_id')->nullable();
            $t->string('product', 12);                                // milk | eggs | wool | other
            $t->date('recorded_on');
            $t->decimal('quantity', 14, 3);
            $t->string('unit', 12);                                    // l, dozen, kg…
            $t->string('grade', 30)->nullable();
            $t->string('lot_code', 60)->nullable();
            $t->boolean('withheld')->default(false);                   // from animals under withdrawal: not for sale
            $t->string('withheld_reason', 200)->nullable();
            $t->string('status', 12)->default('recorded');             // recorded | sold | discarded
            $t->decimal('value', 14, 2)->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['group_id', 'recorded_on']);
        });
        Schema::create('feed_rations', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('name', 120);
            $t->unsignedBigInteger('species_id')->nullable();
            $t->string('notes', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('feed_ration_items', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('ration_id')->constrained('feed_rations')->cascadeOnDelete();
            $t->unsignedBigInteger('stock_item_id');
            $t->decimal('share_pct', 6, 3);                            // of the ration's weight; a ration adds up to 100
            $t->timestamps();
        });
        Schema::create('feedings', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('group_id')->nullable();
            $t->unsignedBigInteger('animal_id')->nullable();
            $t->unsignedBigInteger('ration_id')->nullable();
            $t->unsignedBigInteger('stock_item_id')->nullable();       // a single feed, without a ration
            $t->date('fed_on');
            $t->decimal('quantity_kg', 14, 3);
            $t->unsignedInteger('head_count')->nullable();
            $t->decimal('cost', 14, 2)->default(0);
            $t->jsonb('issues')->nullable();                           // [{stock_item_id, kg, movement_id, cost}]
            $t->uuid('client_id')->nullable()->unique();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['group_id', 'fed_on']);
        });
        Schema::create('vaccination_programmes', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('species_id');
            $t->string('name', 120);
            $t->string('product', 120)->nullable();
            $t->unsignedBigInteger('stock_item_id')->nullable();
            $t->string('applies_to', 12)->default('all');              // all | female | male
            $t->unsignedSmallInteger('first_at_age_days')->nullable();
            $t->unsignedSmallInteger('repeat_every_days')->nullable();
            $t->unsignedSmallInteger('withdrawal_days')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        AgriSchema::tenantRls(['livestock_production', 'feed_rations', 'feed_ration_items', 'feedings', 'vaccination_programmes']);
    }

    public function down(): void
    {
        foreach (['vaccination_programmes', 'feedings', 'feed_ration_items', 'feed_rations', 'livestock_production'] as $x) {
            Schema::dropIfExists($x);
        }
        Schema::table('animal_health_records', fn (Blueprint $t) => $t->dropColumn(['notifiable', 'quarantine_days', 'requires_clearance', 'cleared_by', 'cleared_at', 'clearance_note', 'programme_id']));
        Schema::table('animal_events', fn (Blueprint $t) => $t->dropColumn(['expected_on', 'sire_id', 'method']));
        Schema::table('livestock_species', fn (Blueprint $t) => $t->dropColumn(['productive_years', 'breeding_age_months']));
        Schema::table('livestock_groups', fn (Blueprint $t) => $t->dropColumn(['batch_code', 'closed_on', 'target_head', 'quarantine_until']));
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn(['ownership', 'owner_name', 'birth_date_estimated', 'source', 'quarantine_until', 'exit_cause']));
    }
};
