<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Mail\PortalInviteMail;
use App\Modules\Operations\ProjectManagement\Models\MemberInvite;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\MemberInviteService;
use App\Modules\Operations\ProjectManagement\Services\RoleProvisioner;
use App\Modules\Operations\ProjectManagement\Services\TeamService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * The project manager sends a link; an external party ends up in the portal.
 *
 * Before this existed the contractor portal was unreachable for the people it was written for:
 * ProjectMember.user_id was only ever set if a caller already had a login to hand, and the member
 * picker only searches the HR employee directory. These tests are mostly about the ways the link
 * must *not* work.
 */
class PortalInviteTest extends ProjectManagementTestCase
{
    private Company $company;

    private ?Project $project = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->company = Company::create(['name' => 'Invite Co']);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        // A company created bare has no plan, and EnsureModuleIsActive blocks the portal on the
        // entitlement gate before any of this is reached. A real tenant running Project Management
        // has bought it; this is what that looks like.
        $this->entitleCompany($this->company);
    }

    /** Everything happens inside the tenant, as it would in a request. */
    private function inTenant(callable $callback): mixed
    {
        return $this->asCompany($this->company, function () use ($callback) {
            app(RoleProvisioner::class)->ensure();

            return $callback();
        });
    }

    private function contractorMember(array $overrides = []): ProjectMember
    {
        $this->project = $this->project ?? $this->makeProject();

        return app(TeamService::class)->add($this->project, array_merge([
            'display_name' => 'Sipho at Kwaggafontein Civils',
            'email'        => 'sipho@civils.test',
            'role_key'     => 'contractor',
        ], $overrides));
    }

    // ── Issuing ─────────────────────────────────────────────────────────────

    public function test_a_contractor_member_can_be_invited(): void
    {
        $this->inTenant(function () {
            $member = $this->contractorMember();

            $invite = app(MemberInviteService::class)->send($member, null, $this->manager->id);

            $this->assertSame('sipho@civils.test', $invite->email);
            $this->assertTrue($invite->isOpen());
            Mail::assertSent(PortalInviteMail::class);
        });
    }

    /**
     * The token is never stored, only its hash.
     *
     * A database backup should not be a folder of working invitation links.
     */
    public function test_the_token_is_stored_only_as_a_hash(): void
    {
        $this->inTenant(function () {
            $member = $this->contractorMember();
            $invite = app(MemberInviteService::class)->invite($member);

            $stored = MemberInvite::find($invite->id);

            $this->assertNotSame($invite->plainToken, $stored->token_hash);
            $this->assertSame(hash('sha256', $invite->plainToken), $stored->token_hash);
            $this->assertArrayNotHasKey('token_hash', $stored->toArray());
        });
    }

    public function test_an_internal_member_cannot_be_invited_to_the_portal(): void
    {
        $this->inTenant(function () {
            $member = $this->contractorMember(['role_key' => 'team_member', 'display_name' => 'Staffer']);

            $this->expectException(\InvalidArgumentException::class);

            app(MemberInviteService::class)->invite($member);
        });
    }

    public function test_a_member_who_already_has_a_login_can_still_be_sent_a_fresh_link(): void
    {
        /*
         * This used to be refused, and refusing it was a dead end. An invitation is single use, so
         * somebody who lost the email — or who followed the link from a browser already signed in
         * and did not realise it had done anything — could not be sent another, and the project
         * contact they were told to ask was refused too. Nobody could help them.
         */
        $this->inTenant(function () {
            $member = $this->contractorMember();
            $member->update(['user_id' => $this->outsider->id]);

            $invite = app(MemberInviteService::class)->invite($member);

            $this->assertTrue($invite->isOpen());
        });
    }

    public function test_a_fresh_link_never_sets_a_password_on_an_account_that_exists(): void
    {
        /*
         * Which is what makes the above safe. If an invitation could set a password on an existing
         * account, sending one would be a way to take that account over — so accepting only ever
         * creates a password alongside the account it is creating.
         */
        $this->inTenant(function () {
            $member = $this->contractorMember();
            $known = $this->outsider->password;

            $invite = app(MemberInviteService::class)->invite($member, $this->outsider->email);

            app(MemberInviteService::class)->accept($invite, 'a-Strong-Passphrase-9');

            $this->assertSame($known, $this->outsider->fresh()->password,
                'An existing password must survive somebody accepting an invitation to that address.');
        });
    }

    public function test_re_inviting_kills_the_previous_link(): void
    {
        $this->inTenant(function () {
            $member = $this->contractorMember();
            $service = app(MemberInviteService::class);

            $first = $service->invite($member);
            $second = $service->invite($member);

            $this->assertFalse($first->fresh()->isOpen(), 'The superseded link must stop working.');
            $this->assertTrue($second->fresh()->isOpen());
        });
    }

    // ── Accepting ───────────────────────────────────────────────────────────

    public function test_accepting_creates_an_account_and_links_the_membership(): void
    {
        [$member, $token] = $this->inTenant(function () {
            $member = $this->contractorMember();
            $invite = app(MemberInviteService::class)->invite($member);

            return [$member, $invite->plainToken];
        });

        $response = $this->post(route('project-management.invite.accept', ['token' => $token]), [
            'password'              => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response->assertRedirect(route('project-management.portal'));

        $user = User::withoutGlobalScope('company')->where('email', 'sipho@civils.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertSame($user->id, $member->fresh()->user_id);
        $this->assertTrue(Auth::check());
    }

    /**
     * An invitation must never set the password of an account that already exists.
     *
     * Otherwise anybody who can add a project member could take over any account whose address
     * they can guess, by inviting it and then "accepting".
     */
    public function test_an_existing_account_is_linked_but_never_given_a_new_password(): void
    {
        $existing = User::factory()->create([
            'email'    => 'known@civils.test',
            'password' => Hash::make('the-original-password'),
            'status'   => 1,
        ]);
        $originalHash = $existing->password;

        $token = $this->inTenant(function () {
            $member = $this->contractorMember(['email' => 'known@civils.test']);

            return app(MemberInviteService::class)->invite($member)->plainToken;
        });

        $response = $this->post(route('project-management.invite.accept', ['token' => $token]), [
            'password'              => 'attacker-chosen-password',
            'password_confirmation' => 'attacker-chosen-password',
        ]);

        $response->assertRedirect(route('login'));

        $this->assertSame($originalHash, $existing->fresh()->password, 'The password must be untouched.');
        $this->assertFalse(Auth::check(), 'Linking an existing account must not sign anybody in.');
        $this->assertSame(
            $existing->id,
            ProjectMember::withoutGlobalScopes()->where('email', 'known@civils.test')->first()->user_id,
        );
    }

    public function test_a_guest_can_load_the_accept_page(): void
    {
        $token = $this->inTenant(fn () => app(MemberInviteService::class)
            ->invite($this->contractorMember())->plainToken);

        $this->get(route('project-management.invite.show', ['token' => $token]))
            ->assertOk();
    }

    public function test_an_unknown_token_is_refused_without_leaking_anything(): void
    {
        $response = $this->get(route('project-management.invite.show', ['token' => str_repeat('x', 64)]));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['valid']);
        $this->assertArrayNotHasKey('email', $props);
        $this->assertArrayNotHasKey('project', $props);
    }

    public function test_an_expired_invitation_says_so_rather_than_working(): void
    {
        $token = $this->inTenant(function () {
            $invite = app(MemberInviteService::class)->invite($this->contractorMember());
            $invite->update(['expires_at' => now()->subDay()]);

            return $invite->plainToken;
        });

        $response = $this->get(route('project-management.invite.show', ['token' => $token]));

        $this->assertFalse($response->viewData('page')['props']['valid']);
        $this->assertSame(
            'project_management.invites.expired',
            $response->viewData('page')['props']['reason'],
        );

        $this->post(route('project-management.invite.accept', ['token' => $token]), [
            'password'              => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $this->assertFalse(Auth::check());
        $this->assertDatabaseMissing('users', ['email' => 'sipho@civils.test']);
    }

    public function test_a_revoked_invitation_stops_working(): void
    {
        $token = $this->inTenant(function () {
            $service = app(MemberInviteService::class);
            $invite = $service->invite($this->contractorMember());
            $service->revoke($invite, $this->manager->id);

            return $invite->plainToken;
        });

        $response = $this->get(route('project-management.invite.show', ['token' => $token]));

        $this->assertSame(
            'project_management.invites.revoked',
            $response->viewData('page')['props']['reason'],
        );
    }

    public function test_an_invitation_cannot_be_used_twice(): void
    {
        $token = $this->inTenant(fn () => app(MemberInviteService::class)
            ->invite($this->contractorMember())->plainToken);

        $this->post(route('project-management.invite.accept', ['token' => $token]), [
            'password'              => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        Auth::logout();

        $response = $this->get(route('project-management.invite.show', ['token' => $token]));

        $this->assertSame(
            'project_management.invites.already_accepted',
            $response->viewData('page')['props']['reason'],
        );
    }

    // ── The point of the whole thing ────────────────────────────────────────

    public function test_an_invited_contractor_reaches_the_portal_and_sees_their_project(): void
    {
        $token = $this->inTenant(fn () => app(MemberInviteService::class)
            ->invite($this->contractorMember())->plainToken);

        $this->post(route('project-management.invite.accept', ['token' => $token]), [
            'password'              => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response = $this->get(route('project-management.portal'));
        $response->assertOk();

        $projects = $response->viewData('page')['props']['projects'];

        $this->assertCount(1, $projects, 'The invited contractor should see exactly their project.');
        $this->assertSame('contractor', $projects[0]['role']);
        // Cost never reaches an external party. The portal builds its payload field by field, and
        // this is the assertion that keeps it that way.
        $this->assertArrayNotHasKey('budget_amount', $projects[0]);
    }
}
