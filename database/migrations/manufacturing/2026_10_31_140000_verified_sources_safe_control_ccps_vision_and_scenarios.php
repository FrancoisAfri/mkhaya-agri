<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap phases 4 to 8.
 *
 *  - An item from a community or unverified source can be verified by a person; until then nothing
 *    is escalated from it.
 *  - A device is validated before it takes live commands (until then commands are simulated), and
 *    is due for calibration on an interval.
 *  - A threshold can be a HACCP critical control point: breaching it puts the running batch on hold.
 *  - Photos analysed by the vision model, kept with the finding and the model that made it.
 *  - Scenarios run for a cycle or a type, and which forecast model is the champion for each measure.
 */
return new class extends Migration
{
    private array $tables = [];

    public function up(): void
    {
        Schema::table('prd_publications', function (Blueprint $t) {
            $t->timestampTz('verified_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('verification_note', 255)->nullable();
        });
        Schema::table('prd_devices', function (Blueprint $t) {
            $t->unsignedSmallInteger('calibration_interval_days')->nullable();
            $t->timestampTz('validated_at')->nullable();
            $t->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('prd_thresholds', function (Blueprint $t) {
            $t->boolean('is_ccp')->default(false);
            $t->string('corrective_action', 500)->nullable();
        });

        $this->create('prd_photo_analyses', function (Blueprint $t) {
            $t->foreignId('cycle_id')->nullable()->constrained('prd_cycles')->cascadeOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained('prd_units')->nullOnDelete();
            $t->string('subject', 30);                                    // birds | litter | crop | fish | product | other
            $t->text('question')->nullable();
            $t->string('mime', 60);
            $t->text('image_data');                                       // base64, kept in the database
            $t->string('status', 14)->default('pending');                 // done | unavailable | failed
            $t->jsonb('findings')->nullable();
            $t->string('model', 80)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('review_note')->nullable();
        });
        $this->create('prd_scenarios', function (Blueprint $t) {
            $t->foreignId('cycle_id')->nullable()->constrained('prd_cycles')->nullOnDelete();
            $t->foreignId('production_type_id')->nullable()->constrained('prd_production_types')->nullOnDelete();
            $t->string('name', 160);
            $t->jsonb('inputs');
            $t->jsonb('results');
            $t->jsonb('best')->nullable();
            $t->string('model', 60);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        $this->create('prd_model_choices', function (Blueprint $t) {
            $t->string('metric', 40);
            $t->string('model', 60);
            $t->string('model_version', 20);
            $t->decimal('mape', 10, 4)->nullable();
            $t->unsignedInteger('forecasts')->default(0);
            $t->timestampTz('chosen_at');
            $t->jsonb('compared')->nullable();
            $t->unique(['company_id', 'metric']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach ($this->tables as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        foreach (['prd_model_choices', 'prd_scenarios', 'prd_photo_analyses'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('prd_thresholds', fn (Blueprint $t) => $t->dropColumn(['is_ccp', 'corrective_action']));
        Schema::table('prd_devices', fn (Blueprint $t) => $t->dropColumn(['calibration_interval_days', 'validated_at', 'validated_by']));
        Schema::table('prd_publications', fn (Blueprint $t) => $t->dropColumn(['verified_at', 'verified_by', 'verification_note']));
    }

    private function create(string $table, callable $columns): void
    {
        Schema::create($table, function (Blueprint $t) use ($columns) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $columns($t);
            $t->timestamps();
        });
        $this->tables[] = $table;
    }
};
