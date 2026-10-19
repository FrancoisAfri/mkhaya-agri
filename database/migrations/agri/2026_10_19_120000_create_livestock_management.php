<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module C — livestock. Animals are tracked individually (cattle, horses, breeding stock) or as a
 * group with a head count (broilers, layers, a flock of sheep); both live in groups on a farm.
 *
 * Exits (sold, died, culled, slaughtered) end an animal's active life but never delete it, and a
 * treatment's withdrawal period stops an animal or group being sold or slaughtered until it ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livestock_species', function (Blueprint $table) {
            AgriSchema::owned($table);
            $table->string('code', 30);
            $table->string('name', 80);
            $table->string('tracking', 12)->default('individual'); // individual | group
            $table->unsignedSmallInteger('gestation_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX livestock_species_company_code_unique ON livestock_species (company_id, lower(code))');

        Schema::create('livestock_breeds', function (Blueprint $table) {
            AgriSchema::owned($table);
            $table->foreignId('species_id')->constrained('livestock_species')->restrictOnDelete();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['species_id', 'name']);
        });

        Schema::create('livestock_groups', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('species_id')->constrained('livestock_species')->restrictOnDelete();
            $table->foreignId('field_id')->nullable()->constrained('farm_fields')->nullOnDelete(); // camp / paddock / house
            $table->string('code', 30);
            $table->string('name', 120);
            // beef | dairy | breeding | weaners | feedlot | wool | mutton | layers | broilers | pork | other
            $table->string('purpose', 20)->default('other');
            $table->unsignedInteger('head_count')->default(0); // group-tracked stock; individual groups count animals
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->string('status', 12)->default('active'); // active | closed
            $table->date('started_on')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX livestock_groups_farm_code_unique ON livestock_groups (farm_id, lower(code))');

        Schema::create('animals', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('species_id')->constrained('livestock_species')->restrictOnDelete();
            $table->foreignId('breed_id')->nullable()->constrained('livestock_breeds')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('livestock_groups')->nullOnDelete();
            $table->foreignId('field_id')->nullable()->constrained('farm_fields')->nullOnDelete();
            $table->string('tag_number', 40);           // ear tag / brand / ring — unique in the organisation
            $table->string('electronic_id', 40)->nullable(); // RFID
            $table->string('name', 80)->nullable();
            $table->string('sex', 8);                   // female | male | castrate | unknown
            $table->date('birth_date')->nullable();
            $table->string('acquisition', 12)->default('born'); // born | purchased | transferred
            $table->date('acquired_on')->nullable();
            $table->decimal('acquisition_cost', 14, 2)->nullable();
            $table->unsignedBigInteger('dam_id')->nullable();
            $table->unsignedBigInteger('sire_id')->nullable();
            // active | sold | died | culled | slaughtered | missing | transferred
            $table->string('status', 12)->default('active');
            $table->date('exited_on')->nullable();
            $table->string('exit_reason', 300)->nullable();
            $table->decimal('exit_value', 14, 2)->nullable();
            $table->unsignedBigInteger('exit_journal_id')->nullable();
            $table->decimal('last_weight_kg', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'status']);
            $table->index('group_id');
        });
        DB::statement('CREATE UNIQUE INDEX animals_company_tag_unique ON animals (company_id, species_id, lower(tag_number))');

        Schema::create('animal_movements', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('animal_id')->nullable()->constrained('animals')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('livestock_groups')->nullOnDelete();
            $table->unsignedBigInteger('from_field_id')->nullable();
            $table->unsignedBigInteger('to_field_id')->nullable();
            $table->unsignedBigInteger('from_group_id')->nullable();
            $table->unsignedBigInteger('to_group_id')->nullable();
            $table->unsignedBigInteger('to_farm_id')->nullable();
            $table->unsignedInteger('head_count')->default(1);
            $table->date('moved_on');
            $table->string('reason', 300)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('animal_health_records', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('animal_id')->nullable()->constrained('animals')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('livestock_groups')->nullOnDelete();
            // vaccination | treatment | deworming | dipping | vet_visit | diagnosis | castration | dehorning | other
            $table->string('event_type', 20);
            $table->date('treated_on');
            $table->string('condition', 200)->nullable();
            $table->string('product_name', 150)->nullable();
            $table->unsignedBigInteger('stock_item_id')->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('dose', 60)->nullable();
            $table->unsignedInteger('head_count')->default(1);
            $table->unsignedSmallInteger('withdrawal_days')->nullable();
            $table->date('withdrawal_until')->nullable(); // no sale / slaughter / milk before this date
            $table->date('next_due_on')->nullable();      // booster / follow-up
            $table->string('vet_name', 120)->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'treated_on']);
            $table->index(['animal_id', 'withdrawal_until']);
        });

        Schema::create('animal_events', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('animal_id')->constrained('animals')->cascadeOnDelete();
            // birth | weaning | mating | pregnancy_check | calving | lambing | kidding | milk_recording | exit | other
            $table->string('event_type', 20);
            $table->date('occurred_on');
            $table->string('outcome', 200)->nullable();
            $table->jsonb('details')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['animal_id', 'occurred_on']);
        });

        Schema::create('animal_weights', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('animal_id')->nullable()->constrained('animals')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('livestock_groups')->nullOnDelete();
            $table->date('weighed_on');
            $table->decimal('weight_kg', 10, 2);          // per animal (group: the average)
            $table->unsignedInteger('head_count')->default(1);
            $table->decimal('condition_score', 3, 1)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['animal_id', 'weighed_on']);
        });

        // Changes to a group-tracked head count, each with its reason — the count is never just edited.
        Schema::create('livestock_count_changes', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('group_id')->constrained('livestock_groups')->restrictOnDelete();
            $table->date('changed_on');
            $table->integer('delta');
            // birth | purchase | transfer_in | death | sale | slaughter | theft | transfer_out | count_correction
            $table->string('reason', 20);
            $table->unsignedInteger('count_after');
            $table->decimal('value', 14, 2)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'changed_on']);
        });

        Schema::table('field_activities', function (Blueprint $table) {
            $table->foreign('livestock_group_id')->references('id')->on('livestock_groups')->nullOnDelete();
        });

        AgriSchema::tenantRls(['livestock_species', 'livestock_breeds', 'livestock_groups', 'animals', 'animal_movements',
            'animal_health_records', 'animal_events', 'animal_weights', 'livestock_count_changes']);
    }

    public function down(): void
    {
        Schema::table('field_activities', fn (Blueprint $t) => $t->dropForeign(['livestock_group_id']));
        foreach (['livestock_count_changes', 'animal_weights', 'animal_events', 'animal_health_records', 'animal_movements',
            'animals', 'livestock_groups', 'livestock_breeds', 'livestock_species'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
