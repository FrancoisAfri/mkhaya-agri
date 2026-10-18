<?php

namespace Tests\Feature\Core;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A client is not an employee, and the system can now say so.
 *
 * Clients and subcontractors already have logins. Nothing recorded that they were not staff: the
 * business record sits in `suppliers`, the login sits in `users`, and they are joined only by a
 * matching email address — so the account appears in Manage Users among the employees with the
 * ordinary `user` role and reads exactly like a member of staff.
 *
 * The trap these tests exist for is the second one. The edit screen assigns roles with
 * `syncRoles([$role])`, which replaces rather than adds, so marking somebody a client takes the
 * `user` role away — and with it the bare `view` permission that EnforceCrudPermissions falls back
 * on. A label meant to describe somebody would have locked them out of the product instead.
 */
class ClientAndSubcontractorRolesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_roles_exist(): void
    {
        foreach (['client', 'subcontractor'] as $name) {
            $this->assertNotNull(
                Role::where('name', $name)->where('guard_name', 'web')->first(),
                "There is no {$name} role, so nothing can be marked as one."
            );
        }
    }

    /**
     * The one that matters: marking somebody must not shut them out.
     *
     * `syncRoles` replaces, so this asserts what a client is left holding — not what the migration
     * intended to give them.
     */
    public function test_marking_somebody_a_client_does_not_take_away_their_access(): void
    {
        $user = $this->account();
        $user->syncRoles(['client']);

        /*
         * Asserted outright rather than against whatever the `user` role happens to hold.
         *
         * Comparing the two and skipping when the baseline is empty is how this test would stop
         * running on a seeded database and never say so — and the thing it guards, somebody being
         * locked out by a label, is silent in exactly the same way.
         */
        $this->assertTrue(
            $user->fresh()->can('view'),
            'A client cannot view anything. EnforceCrudPermissions falls back on the bare "view" '
            .'permission, so marking somebody a client would shut them out of the product.'
        );

        // And where there is a baseline to compare with, a client matches it exactly.
        $baseline = Role::where('name', 'user')->first()?->permissions->pluck('name')->sort()->values();

        if ($baseline && $baseline->isNotEmpty()) {
            $this->assertSame(
                $baseline->all(),
                $user->fresh()->getPermissionNames()->sort()->values()->all(),
                'A client holds different permissions from an ordinary user, so the label changed their access.'
            );
        }
    }

    public function test_a_subcontractor_keeps_the_same_access(): void
    {
        $user = $this->account();
        $user->syncRoles(['subcontractor']);

        $this->assertTrue($user->fresh()->can('view'));
    }

    /** And the label is visible where somebody would look for it. */
    public function test_the_role_is_what_manage_users_shows(): void
    {
        $user = $this->account();
        $user->syncRoles(['client']);

        $this->assertSame(['client'], $user->fresh()->getRoleNames()->all());
    }

    private function account(): User
    {
        return User::withoutGlobalScopes()->create([
            'uuid'       => (string) Str::uuid(),
            'email'      => 'acct-'.Str::lower(Str::random(8)).'@mkhaya.test',
            'password'   => Hash::make(Str::random(16)),
            'company_id' => DB::table('companies')->min('id'),
            'status'     => 1,
        ]);
    }
}
