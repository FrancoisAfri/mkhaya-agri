<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Whose deals somebody is shown when they open the board.
 *
 * Two questions were one before this: what a person is *permitted* to see, and what they are
 * *looking at*. A manager is permitted to see their whole team, and the board answered that by
 * opening on forty colleagues' cards — which buries the four that are theirs and is much the same
 * as not having a board.
 *
 * So the board opens on your own work, and seeing the team's is a button. The permission model is
 * untouched: the scope can only ever narrow what `visibleOwnerIds()` already allows, never widen
 * it, which is what stops the query parameter becoming a way to read a colleague's pipeline.
 */
class DealVisibilityScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string ...$roles): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => 'crm+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'),
            'status' => 1,
            'company_id' => DB::table('companies')->min('id'),
        ]);

        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }

        if ($roles !== []) {
            $user->syncRoles($roles);
        }

        return $user->fresh();
    }

    private function visibility(): CrmVisibilityService
    {
        return app(CrmVisibilityService::class);
    }

    public function test_a_rep_opens_the_board_on_their_own_deals(): void
    {
        $rep = $this->user('sales consultant');

        $this->assertSame(
            [(int) $rep->id],
            $this->visibility()->ownerIdsForScope(CrmVisibilityService::SCOPE_MINE, $rep),
        );
    }

    public function test_an_executive_also_opens_on_their_own(): void
    {
        /*
         * The change that matters. An executive may see everything, and used to be shown everything
         * the moment they opened the board — their own deals somewhere in among the company's.
         */
        $exec = $this->user('executive');

        $this->assertSame(
            [(int) $exec->id],
            $this->visibility()->ownerIdsForScope(CrmVisibilityService::SCOPE_MINE, $exec),
            'Being allowed to see everything is not a reason to be shown everything.',
        );
    }

    public function test_an_executive_asking_for_the_team_gets_everything(): void
    {
        $exec = $this->user('executive');

        $this->assertNull(
            $this->visibility()->ownerIdsForScope(CrmVisibilityService::SCOPE_TEAM, $exec),
            'Null means unrestricted — the whole company.',
        );
    }

    public function test_a_rep_asking_for_the_team_still_only_gets_their_own(): void
    {
        /*
         * The scope arrives in the query string, so it has to be incapable of widening anything.
         * A consultant typing ?scope=team is asking a question the permission model answers with
         * their own id.
         */
        $rep = $this->user('sales consultant');

        $this->assertSame(
            [(int) $rep->id],
            $this->visibility()->ownerIdsForScope(CrmVisibilityService::SCOPE_TEAM, $rep),
        );
    }

    public function test_the_toggle_is_only_offered_to_somebody_with_a_team(): void
    {
        // Drawing it for a rep would be a control that changes nothing when pressed.
        $this->assertFalse($this->visibility()->seesOthers($this->user('sales consultant')));
        $this->assertTrue($this->visibility()->seesOthers($this->user('executive')));
    }

    public function test_an_unrecognised_scope_falls_back_to_your_own(): void
    {
        $exec = $this->user('executive');

        $this->assertSame(
            CrmVisibilityService::SCOPE_MINE,
            $this->visibility()->scopeFromRequest('everything', $exec),
        );
        $this->assertSame(
            CrmVisibilityService::SCOPE_MINE,
            $this->visibility()->scopeFromRequest(null, $exec),
        );
    }

    public function test_a_rep_cannot_talk_their_way_into_the_team_scope(): void
    {
        $rep = $this->user('sales consultant');

        $this->assertSame(
            CrmVisibilityService::SCOPE_MINE,
            $this->visibility()->scopeFromRequest('team', $rep),
        );
    }

    public function test_an_unassigned_deal_is_visible_to_everybody(): void
    {
        /*
         * A deal nobody owns is work waiting to be picked up. Reading "mine" strictly would hide it
         * from every single person — the rep does not own it, and the manager looking at their team
         * does not either — so it would sit there until somebody went looking, which nobody does.
         *
         * It is also what an installation looks like before anything has been assigned, and an
         * empty board on day one reads as the feature being broken rather than as an empty pipeline.
         */
        $rep = $this->user('sales consultant');

        $sql = $this->visibility()
            ->scopeOwner(CrmDeal::query(), user: $rep, scope: CrmVisibilityService::SCOPE_MINE)
            ->toSql();

        $this->assertStringContainsString('is null', strtolower($sql), 'Unowned deals have to survive the narrowing.');
    }

    public function test_the_other_crm_lists_are_left_alone(): void
    {
        /*
         * The ask was about the deals board. Leads, contacts, accounts, activities and calls call
         * the same method without a scope and must keep showing what they always showed — changing
         * those is a decision for whoever owns those screens, not a side effect of this one.
         */
        $exec = $this->user('executive');

        $this->assertNull(
            $this->visibility()->visibleOwnerIds($exec),
            'Without a scope, an executive still sees everything.',
        );

        $unscoped = $this->visibility()->scopeOwner(CrmDeal::query(), user: $exec)->toSql();

        $this->assertStringNotContainsString('owner_id', $unscoped);
    }

    public function test_the_query_is_narrowed_to_the_scope(): void
    {
        /*
         * End to end on the query the board actually runs, rather than on the id list alone — the
         * two have to agree or the list is right and the board still shows everything.
         */
        $rep = $this->user('sales consultant');
        $other = $this->user('sales consultant');

        $sql = $this->visibility()
            ->scopeOwner(CrmDeal::query(), user: $rep, scope: CrmVisibilityService::SCOPE_MINE)
            ->toSql();

        $this->assertStringContainsString('owner_id', $sql);

        $bindings = $this->visibility()
            ->scopeOwner(CrmDeal::query(), user: $rep, scope: CrmVisibilityService::SCOPE_MINE)
            ->getBindings();

        $this->assertContains($rep->id, $bindings);
        $this->assertNotContains($other->id, $bindings);
    }
}
