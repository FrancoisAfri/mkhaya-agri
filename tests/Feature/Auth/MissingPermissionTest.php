<?php

namespace Tests\Feature\Auth;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Asking about a permission nobody defined.
 *
 * Spatie throws PermissionDoesNotExist rather than returning false, so a policy naming a permission
 * the seeders never created answered 500 — and a 500 on a create screen reads as "this system is
 * broken", not "you may not do this". Three were live at once: `offers.create` and `contracts.send`
 * were in no seeder at all, and AssetPolicy asked for `assets.update` where the seeder made
 * `assets.edit`. Offers could not be opened by anyone.
 *
 * `EnforceCrudPermissions` had always caught this for route-derived checks. The 138 direct calls in
 * policies and form requests had not.
 */
class MissingPermissionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The permission catalogue is seeded here rather than assumed.
     *
     * This class uses DatabaseTransactions, so it inherits whatever is in the test database — and
     * a RefreshDatabase test elsewhere in the suite truncates the permission tables. Running the
     * seeder is also the honest way to assert "the application defines these": it checks what
     * ships, not what happens to be left over.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'perm+'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'status' => 1,
            'company_id' => DB::table('companies')->min('id'),
        ]);
    }

    public function test_an_undefined_permission_is_denied_rather_than_thrown(): void
    {
        $this->assertFalse(
            $this->user()->hasPermissionTo('nothing.has.ever.defined.this'),
            'An undefined permission did not deny cleanly.',
        );
    }

    /** The three that were actually live in production code. */
    public function test_the_permissions_that_broke_offers_contracts_and_assets_now_exist(): void
    {
        foreach (['offers.create', 'contracts.send', 'assets.update'] as $permission) {
            $this->assertNotNull(
                \Spatie\Permission\Models\Permission::where('name', $permission)->first(),
                "{$permission} is checked in a policy but exists in no seeder.",
            );
        }
    }

    /** A permission that does exist must still answer honestly for a user who lacks it. */
    public function test_a_defined_permission_the_user_lacks_is_still_false(): void
    {
        $this->assertFalse($this->user()->hasPermissionTo('offers.create'));
    }

    /** And true for one they hold — the half that is easy to break while fixing the other. */
    public function test_a_permission_the_user_holds_is_true(): void
    {
        $user = $this->user();
        $user->givePermissionTo('offers.create');

        $this->assertTrue($user->fresh()->hasPermissionTo('offers.create'));
    }
}
