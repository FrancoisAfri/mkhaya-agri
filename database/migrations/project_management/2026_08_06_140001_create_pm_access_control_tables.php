<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access control for Project Management (EPIC-002: PM-1020…1023).
 *
 *  pm_roles                 per-tenant roles — the nine built-ins, plus clones a tenant edits.
 *  pm_project_members       who is on a project, and in which role there.
 *  pm_permission_overrides  explicit allow/deny for one person, globally or on one project.
 *
 * Why PM owns roles rather than extending Spatie: Spatie's roles are global (no company or team
 * column here) and its permissions are flat strings, so it cannot express "Project Manager on the
 * Sandton job, Read Only everywhere else". Spatie stays as the coarse module grant; anything
 * project-scoped is evaluated by PermissionEvaluator against these tables.
 */
return new class extends Migration
{
    private const TABLES = ['pm_roles', 'pm_project_members', 'pm_permission_overrides'];

    public function up(): void
    {
        // ── Roles ─────────────────────────────────────────────────────────────
        Schema::create('pm_roles', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('key', 40);                       // project_manager, client, …
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            // The permissions this role grants. Denormalised deliberately: a role is read on every
            // authorisation check, and a jsonb array avoids a join per check.
            $t->json('permissions');
            // Built-ins are reseeded on upgrade to pick up new permissions; clones are never touched.
            $t->boolean('is_builtin')->default(false);
            $t->unsignedBigInteger('cloned_from_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // ── Project membership ────────────────────────────────────────────────
        Schema::create('pm_project_members', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            // A member may have no login: a contractor's foreman who exists only as a name until
            // (or unless) HR is installed. Identity then hangs off external_ref_id.
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('external_ref_id')->nullable();
            $t->string('role_key', 40);
            $t->string('display_name', 190)->nullable();
            $t->string('email', 190)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('external_ref_id')->references('id')->on('pm_external_refs')->nullOnDelete();

            // The evaluator's read path: "what is this user's role on this project?"
            $t->index(['company_id', 'user_id', 'project_id']);
            $t->index(['company_id', 'project_id', 'is_active']);
        });

        // ── Overrides ─────────────────────────────────────────────────────────
        // A deny here beats every grant, which is what makes "most permissive wins, deny rules
        // override" (PM-1023) expressible at all.
        Schema::create('pm_permission_overrides', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('project_id')->nullable();   // null = applies everywhere
            $t->string('permission', 60);
            $t->string('effect', 5);                            // allow | deny
            $t->string('reason', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'user_id', 'project_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX pm_roles_key_unique ON pm_roles (company_id, key) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX pm_project_members_unique ON pm_project_members (company_id, project_id, user_id) WHERE deleted_at IS NULL AND user_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX pm_permission_overrides_unique ON pm_permission_overrides (company_id, user_id, project_id, permission) WHERE deleted_at IS NULL');
            DB::statement("ALTER TABLE pm_permission_overrides ADD CONSTRAINT pm_permission_overrides_effect_chk CHECK (effect IN ('allow','deny'))");

            $this->applyTenantRls(self::TABLES);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function applyTenantRls(array $tables): void
    {
        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
