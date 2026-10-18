<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Letting an external party actually reach the portal built for them.
 *
 * ── The gap this closes ─────────────────────────────────────────────────────
 *
 * A ProjectMember carries a nullable `user_id`, and TeamService only ever set it when a caller
 * already had one to hand. The member picker searches the HR employee directory, which a client's
 * quantity surveyor and a subcontractor's foreman are not in. So the only way an external person
 * could reach the client/contractor portal was for somebody to have created them a Hub login by
 * another route and then to have known to pass its id — which nothing in the interface offered.
 * In practice the portal was unreachable for exactly the people it exists for.
 *
 * The project manager now sends them a link. That is the whole of it.
 *
 * ── Why a token table rather than a signed URL ──────────────────────────────
 *
 * Laravel's signed URLs cannot be revoked, and an invitation to see a client's project is
 * something a project manager must be able to take back — somebody leaves, an address turns out
 * to be wrong, a contract ends before the work starts. A row can be revoked; a signature cannot.
 * It also gives an audit trail of who invited whom and when it was taken up, which a signature in
 * an email does not.
 *
 * `token_hash` stores a SHA-256 of the token, never the token. The plain value exists only in the
 * email. A leaked database backup is then not a set of working invitation links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_member_invites', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('member_id')->index();

            // Kept alongside the member's own email on purpose: the invitation was sent *here*,
            // and someone editing the member's address later must not silently redirect an
            // outstanding invitation to a different inbox.
            $t->string('email', 190);
            $t->char('token_hash', 64);
            $t->string('role_key', 40);

            $t->unsignedBigInteger('invited_by')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->unsignedBigInteger('accepted_user_id')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('revoked_by')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('member_id')->references('id')->on('pm_project_members')->cascadeOnDelete();
            $t->index(['company_id', 'project_id']);
            $t->index('expires_at');
        });

        // The lookup path on an unauthenticated request: one index hit, no scan, and unique so a
        // token can never resolve to two invitations. Partial on deleted_at per PM-ADR-007 —
        // a hard UNIQUE would let a soft-deleted row block its own replacement.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX pm_member_invites_token_unique ON pm_member_invites (token_hash) '
                . 'WHERE deleted_at IS NULL'
            );

            // One live invitation per member. Re-inviting supersedes rather than accumulating, so
            // revoking "the" invitation cannot leave an older working link behind it.
            DB::statement(
                'CREATE UNIQUE INDEX pm_member_invites_open_unique ON pm_member_invites (member_id) '
                . 'WHERE deleted_at IS NULL AND accepted_at IS NULL AND revoked_at IS NULL'
            );
        } else {
            Schema::table('pm_member_invites', fn (Blueprint $t) => $t->unique('token_hash'));
        }

        $this->applyTenantRls(['pm_member_invites']);
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_member_invites');
    }

    /**
     * Tenant isolation in the database, not only in the ORM.
     *
     * A NULL session variable disables the filter, which is what lets seeders, console jobs and —
     * here specifically — the unauthenticated accept request read the row before any tenant has
     * been resolved. Copied per-migration because this module has no shared migration base class;
     * TenantSchemaTest fails the build if a pm_ table is ever missing it.
     */
    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

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
