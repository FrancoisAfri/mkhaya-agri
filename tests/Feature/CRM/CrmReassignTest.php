<?php

namespace Tests\Feature\CRM;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Services\CrmReassignService;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A sales manager can hand their team's records to another person on the team — and cannot reach
 * past the edge of that team in either direction.
 *
 * The reporting tree comes from HR (hr_people.manager_id), so these tests build a real one: a
 * manager, two of their reps, and an unrelated rep under nobody.
 */
class CrmReassignTest extends TestCase
{
    use DatabaseTransactions;

    private User $manager;
    private User $repA;
    private User $repB;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateCrm();
        $this->buildTeam();
    }

    private function activateCrm(): void
    {
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'crm'],
            ['uuid' => (string) Str::uuid(), 'name' => 'CRM', 'path' => 'crm', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );

        Cache::flush();
    }

    private function makeUser(string $tag): User
    {
        return User::forceCreate([
            'uuid'              => (string) Str::uuid(),
            'email'             => $tag . '+' . Str::random(8) . '@example.test',
            'password'          => bcrypt('secret'),
            'status'            => 1,
            'is_platform_admin' => true,   // clears the hub-tier gate; the sales role drives visibility
        ]);
    }

    /** An HR person row, because the reporting tree is read from HR, not from CRM. */
    private function person(User $user, ?int $managerPersonId = null): int
    {
        return (int) DB::table('hr_people')->insertGetId([
            'user_id'    => $user->id,
            'first_name' => Str::random(6),
            'surname'    => 'Tester',
            'manager_id' => $managerPersonId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildTeam(): void
    {
        foreach (['sales manager', 'sales consultant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->manager  = $this->makeUser('manager');
        $this->repA     = $this->makeUser('repa');
        $this->repB     = $this->makeUser('repb');
        $this->outsider = $this->makeUser('outsider');

        $this->manager->assignRole('sales manager');
        $this->repA->assignRole('sales consultant');
        $this->repB->assignRole('sales consultant');
        $this->outsider->assignRole('sales consultant');

        $managerPerson = $this->person($this->manager);
        $this->person($this->repA, $managerPerson);
        $this->person($this->repB, $managerPerson);
        $this->person($this->outsider);          // reports to nobody — a different team entirely
    }

    private function lead(User $owner, string $name = 'Test'): CrmLead
    {
        return CrmLead::forceCreate([
            'uuid'       => (string) Str::uuid(),
            'first_name' => $name,
            'last_name'  => 'Lead',
            'status'     => 'new',
            'owner_id'   => $owner->id,
        ]);
    }

    // ── Who can see whom ─────────────────────────────────────────────────────

    public function test_a_manager_sees_their_whole_reporting_tree_and_a_consultant_only_themselves(): void
    {
        $visibility = app(CrmVisibilityService::class);

        $managerSees = $visibility->visibleOwnerIds($this->manager);
        $this->assertContains($this->repA->id, $managerSees);
        $this->assertContains($this->repB->id, $managerSees);
        $this->assertContains($this->manager->id, $managerSees);
        $this->assertNotContains($this->outsider->id, $managerSees, 'A manager must not see another team.');

        $this->assertSame([$this->repA->id], $visibility->visibleOwnerIds($this->repA));
    }

    public function test_the_reassign_picker_offers_only_the_managers_own_team(): void
    {
        $this->actingAs($this->manager);

        $ids = array_column(app(CrmVisibilityService::class)->assignableOwners($this->manager), 'id');

        $this->assertContains($this->repB->id, $ids);
        $this->assertNotContains($this->outsider->id, $ids);
    }

    // ── Reassignment ─────────────────────────────────────────────────────────

    public function test_a_manager_can_move_a_leads_from_one_rep_to_another(): void
    {
        $this->actingAs($this->manager);
        $lead = $this->lead($this->repA);

        $result = app(CrmReassignService::class)->reassign('lead', [$lead->id], $this->repB->id);

        $this->assertSame(1, $result['moved']);
        $this->assertSame($this->repB->id, $lead->fresh()->owner_id);
    }

    public function test_open_work_follows_the_record_but_finished_work_stays_put(): void
    {
        $this->actingAs($this->manager);
        $lead = $this->lead($this->repA);

        $open = CrmActivity::forceCreate([
            'uuid' => (string) Str::uuid(), 'type' => 'task', 'subject' => 'Call them back',
            'related_type' => 'lead', 'related_id' => $lead->id, 'status' => 'pending', 'owner_id' => $this->repA->id,
        ]);
        $done = CrmActivity::forceCreate([
            'uuid' => (string) Str::uuid(), 'type' => 'call', 'subject' => 'Intro call',
            'related_type' => 'lead', 'related_id' => $lead->id, 'status' => 'done',
            'completed_at' => now(), 'owner_id' => $this->repA->id,
        ]);

        $result = app(CrmReassignService::class)->reassign('lead', [$lead->id], $this->repB->id);

        $this->assertSame(1, $result['activities']);
        $this->assertSame($this->repB->id, $open->fresh()->owner_id, 'An unfinished task belongs to the new owner.');
        $this->assertSame($this->repA->id, $done->fresh()->owner_id, 'A completed call stays with whoever made it.');
    }

    public function test_activities_can_be_left_behind_when_asked(): void
    {
        $this->actingAs($this->manager);
        $lead = $this->lead($this->repA);

        $open = CrmActivity::forceCreate([
            'uuid' => (string) Str::uuid(), 'type' => 'task', 'subject' => 'Follow up',
            'related_type' => 'lead', 'related_id' => $lead->id, 'status' => 'pending', 'owner_id' => $this->repA->id,
        ]);

        app(CrmReassignService::class)->reassign('lead', [$lead->id], $this->repB->id, moveOpenActivities: false);

        $this->assertSame($this->repA->id, $open->fresh()->owner_id);
    }

    // ── The edges of the team ────────────────────────────────────────────────

    public function test_a_manager_cannot_reassign_a_record_belonging_to_another_team(): void
    {
        $this->actingAs($this->manager);
        $lead = $this->lead($this->outsider);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CrmReassignService::class)->reassign('lead', [$lead->id], $this->repA->id);
    }

    public function test_a_record_cannot_be_handed_to_someone_outside_the_team(): void
    {
        $this->actingAs($this->manager);
        $lead = $this->lead($this->repA);

        $this->expectException(ValidationException::class);
        app(CrmReassignService::class)->reassign('lead', [$lead->id], $this->outsider->id);
    }

    public function test_a_batch_containing_one_forbidden_record_moves_nothing_at_all(): void
    {
        $this->actingAs($this->manager);
        $mine = $this->lead($this->repA, 'Mine');
        $theirs = $this->lead($this->outsider, 'Theirs');

        try {
            app(CrmReassignService::class)->reassign('lead', [$mine->id, $theirs->id], $this->repB->id);
            $this->fail('A batch containing an unreachable record should have been refused.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($this->repA->id, $mine->fresh()->owner_id, 'The permitted record must not move either.');
        $this->assertSame($this->outsider->id, $theirs->fresh()->owner_id);
    }

    // ── The gap this closed ──────────────────────────────────────────────────

    public function test_a_consultant_cannot_edit_or_delete_a_lead_they_do_not_own(): void
    {
        $lead = $this->lead($this->repA);

        // The list already hid it from them; the point is that addressing it by id doesn't work
        // either, which is what made a reassign button safe to add in the first place.
        $this->actingAs($this->outsider)
            ->patch(route('crm.leads.update', $lead->uuid), ['first_name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->delete(route('crm.leads.destroy', $lead->uuid))
            ->assertForbidden();

        $this->assertSame('Test', $lead->fresh()->first_name);
    }

    public function test_the_owner_may_still_edit_their_own_lead(): void
    {
        $lead = $this->lead($this->repA);

        $this->actingAs($this->repA)
            ->patch(route('crm.leads.update', $lead->uuid), ['first_name' => 'Renamed'])
            ->assertRedirect();

        $this->assertSame('Renamed', $lead->fresh()->first_name);
    }

    public function test_the_endpoint_refuses_a_record_type_it_does_not_know(): void
    {
        $this->actingAs($this->manager)
            ->post(route('crm.reassign'), ['type' => 'invoice', 'ids' => [1], 'owner_id' => $this->repA->id])
            ->assertSessionHasErrors('type');
    }
}
