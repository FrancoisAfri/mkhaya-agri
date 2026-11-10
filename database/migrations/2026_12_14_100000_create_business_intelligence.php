<?php

use Database\Seeders\BIModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business Intelligence: saved dashboards (a copied template or one built from scratch) and the
 * saved filter views on them. Templates themselves live in code and need no rows. Registers the
 * sellable `business_intelligence` module and its menu entry.
 */
return new class extends Migration
{
    private const SESSION = "NULLIF(current_setting('app.company_id', true), '')";

    public function up(): void
    {
        Schema::create('bi_dashboards', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name', 150);
            $t->string('description', 500)->nullable();
            $t->string('visibility', 20)->default('private');   // private | shared | organisation
            $t->jsonb('shared_roles')->nullable();
            $t->string('template_key', 80)->nullable();          // the template it was copied from
            $t->jsonb('widgets');                                // [{id, type, title, config, x, y, w, h}]
            $t->timestamps();
            $t->index(['company_id', 'visibility']);
        });

        Schema::create('bi_bookmarks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('dashboard', 80);                         // a dashboard uuid or "t:<template key>"
            $t->unsignedBigInteger('user_id');
            $t->string('name', 120);
            $t->jsonb('state');
            $t->timestamps();
            $t->index(['company_id', 'user_id', 'dashboard']);
        });

        $s = self::SESSION;
        foreach (['bi_dashboards', 'bi_bookmarks'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$s}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$s} IS NULL OR company_id = {$s}::bigint) WITH CHECK ({$s} IS NULL OR company_id = {$s}::bigint)");
        }

        (new BIModuleSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_bookmarks');
        Schema::dropIfExists('bi_dashboards');
        $id = DB::table('security_modules')->where('code_name', 'business_intelligence')->value('id');
        if ($id) {
            $ribbons = DB::table('security_module_ribbons')->where('module_id', $id)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbons)->delete();
            DB::table('security_module_ribbons')->where('module_id', $id)->delete();
            DB::table('security_module_access')->where('module_id', $id)->delete();
            DB::table('security_modules')->where('id', $id)->delete();
        }
        DB::table('billing_modules')->where('code', 'business_intelligence')->delete();
    }
};
