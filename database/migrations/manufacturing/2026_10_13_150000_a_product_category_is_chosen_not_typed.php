<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product's category comes from a list the company maintains, rather than free text.
 *
 * Typed categories drift the first week: "Brackets", "brackets", "Bracket" and "BRKT" are four
 * categories to a filter and one to the person who typed them. A chosen category is the same row
 * every time, so filtering, reporting and costing by category mean something.
 *
 * Any categories already typed are carried over as list entries, one per distinct value per
 * company, so nothing anybody entered is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfg_item_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Unique per tenant regardless of case, or "Brackets" and "brackets" come straight back.
        DB::statement('CREATE UNIQUE INDEX mfg_icat_name_uniq ON mfg_item_categories (company_id, lower(name))');

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE mfg_item_categories ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE mfg_item_categories ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE mfg_item_categories FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON mfg_item_categories');
            DB::statement("CREATE POLICY tenant_isolation ON mfg_item_categories USING ({$policy}) WITH CHECK ({$policy})");
        }

        Schema::table('mfg_items', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('kind')
                ->constrained('mfg_item_categories')->nullOnDelete();
        });

        // Carry typed values across. Query builder only: models carry scopes a migration must not
        // depend on, and the session has no tenant applied here.
        $typed = DB::table('mfg_items')
            ->whereNotNull('category')->where('category', '!=', '')
            ->select('company_id', DB::raw('trim(category) as name'))
            ->distinct()->get();

        foreach ($typed as $row) {
            $id = DB::table('mfg_item_categories')
                ->where('company_id', $row->company_id)->whereRaw('lower(name) = lower(?)', [$row->name])
                ->value('id')
                ?? DB::table('mfg_item_categories')->insertGetId([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $row->company_id,
                    'name' => $row->name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);

            DB::table('mfg_items')
                ->where('company_id', $row->company_id)
                ->whereRaw('lower(trim(category)) = lower(?)', [$row->name])
                ->update(['category_id' => $id]);
        }

        Schema::table('mfg_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('mfg_items', function (Blueprint $table) {
            $table->string('category')->nullable();
        });

        DB::statement('UPDATE mfg_items i SET category = c.name FROM mfg_item_categories c WHERE c.id = i.category_id');

        Schema::table('mfg_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('mfg_item_categories');
    }
};
