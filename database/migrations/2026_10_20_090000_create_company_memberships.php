<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One person, several organisations: an agronomist advising three farming businesses, a contractor
 * working for two, an owner with a separate company per farm.
 *
 * users.company_id stays the organisation the person is working in right now — every module reads
 * it, and that does not change. This table says which organisations they may switch to, and with
 * which roles in each: roles are global on this platform (no Spatie teams), so the roles a person
 * holds in one organisation are kept here while they work in another, and put back when they
 * return.
 *
 * Deliberately not row-level-secured: the switcher must list a person's organisations from inside
 * any one of them. Every read is by user id, and the controller checks the actor's authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->jsonb('roles')->nullable();        // role names held in this organisation
            $table->jsonb('permissions')->nullable();  // direct permissions held in this organisation
            $table->string('status', 12)->default('active'); // active | suspended
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'company_id']);
            $table->index(['company_id', 'status']);
        });

        // Everybody already belongs to their own organisation, with the roles they hold today.
        $roles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_type', 'like', '%User')->get(['model_id', 'roles.name'])->groupBy('model_id');
        $perms = DB::table('model_has_permissions')->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_type', 'like', '%User')->get(['model_id', 'permissions.name'])->groupBy('model_id');
        DB::table('users')->whereNotNull('company_id')->orderBy('id')->select('id', 'company_id')
            ->chunk(500, function ($users) use ($roles, $perms) {
                DB::table('company_memberships')->insertOrIgnore($users->map(fn ($u) => [
                    'user_id' => $u->id, 'company_id' => $u->company_id,
                    'roles' => json_encode(($roles[$u->id] ?? collect())->pluck('name')->values()),
                    'permissions' => json_encode(($perms[$u->id] ?? collect())->pluck('name')->values()),
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_memberships');
    }
};
