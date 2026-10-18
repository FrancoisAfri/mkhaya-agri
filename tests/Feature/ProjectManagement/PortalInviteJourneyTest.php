<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\MemberInvite;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\MemberInviteService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Taking up a portal invitation, from the emailed link to being inside.
 *
 * Reported: "it asked me to set up my access, when I clicked it took me to the login page, and now
 * the link says it has already been used — I was never able to set a password."
 *
 * Every step below is walked over HTTP the way the invited person walks it, because the failure was
 * not in any one method. It was in the seam between them.
 */
class PortalInviteJourneyTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        Mail::fake();
    }

    private function client(string $email = 'ops@acme.test'): ProjectMember
    {
        return ProjectMember::create([
            'project_id'   => $this->project->id,
            'display_name' => 'Acme Ltd',
            'role_key'     => 'client',
            'is_active'    => true,
            'email'        => $email,
        ]);
    }

    private function invite(ProjectMember $member): MemberInvite
    {
        return app(MemberInviteService::class)->send($member, null, $this->manager->id);
    }

    // ── The journey it is meant to be ────────────────────────────────────────

    public function test_a_new_client_sets_a_password_and_lands_inside(): void
    {
        $invite = $this->invite($this->client());
        $token = $invite->plainToken;

        $this->get(route('project-management.invite.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/ProjectManagement/Portal/Accept')
                ->where('hasAccount', false));

        $this->post(route('project-management.invite.accept', ['token' => $token]), [
            'password' => 'a-Strong-Passphrase-9',
            'password_confirmation' => 'a-Strong-Passphrase-9',
        ])->assertRedirect(route('project-management.portal'));

        $this->assertAuthenticated();
        $this->assertNotNull(User::where('email', 'ops@acme.test')->first());
    }

    public function test_a_link_that_has_been_used_says_so_rather_than_pretending(): void
    {
        $invite = $this->invite($this->client());
        $token = $invite->plainToken;

        $this->post(route('project-management.invite.accept', ['token' => $token]), [
            'password' => 'a-Strong-Passphrase-9',
            'password_confirmation' => 'a-Strong-Passphrase-9',
        ]);

        $this->post('/logout');

        $this->get(route('project-management.invite.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('valid', false)
                ->where('reason', 'project_management.invites.already_accepted'));
    }

    // ── The reported failure ────────────────────────────────────────────────

    public function test_a_weak_password_does_not_burn_the_invitation(): void
    {
        /*
         * The heart of the report. Somebody types a password, it is refused, and if the attempt has
         * already consumed the invitation there is no second try — the link is dead and the only
         * person who could issue another one is told the member already has a login.
         */
        $invite = $this->invite($this->client());
        $token = $invite->plainToken;

        $this->from(route('project-management.invite.show', ['token' => $token]))
            ->post(route('project-management.invite.accept', ['token' => $token]), [
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])
            ->assertSessionHasErrors('password');

        $this->assertNull(
            MemberInvite::find($invite->id)->accepted_at,
            'A refused password must leave the invitation open.',
        );

        // And the second attempt works.
        $this->post(route('project-management.invite.accept', ['token' => $token]), [
            'password' => 'a-Strong-Passphrase-9',
            'password_confirmation' => 'a-Strong-Passphrase-9',
        ])->assertRedirect(route('project-management.portal'));
    }

    public function test_mismatched_confirmation_does_not_burn_the_invitation(): void
    {
        $invite = $this->invite($this->client());

        $this->post(route('project-management.invite.accept', ['token' => $invite->plainToken]), [
            'password' => 'a-Strong-Passphrase-9',
            'password_confirmation' => 'a-different-Passphrase-9',
        ])->assertSessionHasErrors('password');

        $this->assertNull(MemberInvite::find($invite->id)->accepted_at);
    }

    public function test_an_address_that_already_has_an_account_can_still_get_in(): void
    {
        /*
         * The path the report actually took. The invited address already had an account, so no
         * password was asked for, the click linked the membership and dropped the person at the
         * login screen — with the invitation spent and no way to ask for another, because the
         * member now has a login and inviting again is refused.
         *
         * Being sent to sign in is right; being sent there with no way to prove who you are is not.
         */
        $existing = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ops@acme.test',
            'password' => bcrypt('whatever'), 'status' => 1,
            'company_id' => $this->project->company_id,
        ]);

        $member = $this->client('ops@acme.test');
        $invite = $this->invite($member);

        $this->get(route('project-management.invite.show', ['token' => $invite->plainToken]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('hasAccount', true));

        $this->post(route('project-management.invite.accept', ['token' => $invite->plainToken]))
            ->assertRedirect();

        $this->assertSame($existing->id, $member->fresh()->user_id, 'The membership is linked.');
    }

    public function test_somebody_stuck_at_the_login_screen_can_be_helped(): void
    {
        /*
         * The dead end. Once a membership is linked, `invite()` refuses to send another invitation
         * — so a client who cannot get past the login screen cannot be given a new link by anybody,
         * and the project contact they were told to ask has nothing to give them.
         */
        $existing = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ops@acme.test',
            'password' => bcrypt('whatever'), 'status' => 1,
            'company_id' => $this->project->company_id,
        ]);

        $member = $this->client('ops@acme.test');
        $member->update(['user_id' => $existing->id]);

        $this->actingAs($this->manager)
            ->post(route('project-management.members.invite', [$this->project->uuid, $member->uuid]), [
                'email' => 'ops@acme.test',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_somebody_already_signed_in_is_taken_straight_in(): void
    {
        /*
         * What the report actually hit. Following the link from a browser already signed in as the
         * invited person bounced them to a login they did not need — and the invitation, single
         * use, was spent on the way there. Nothing said what had happened, so it read as a broken
         * link.
         */
        $existing = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ops@acme.test',
            'password' => bcrypt('whatever'), 'status' => 1,
            'company_id' => $this->project->company_id,
        ]);

        $member = $this->client('ops@acme.test');
        $invite = $this->invite($member);

        $this->actingAs($existing)
            ->post(route('project-management.invite.accept', ['token' => $invite->plainToken]))
            ->assertRedirect(route('project-management.portal'));

        $this->assertSame($existing->id, $member->fresh()->user_id);
    }

    public function test_the_login_message_names_the_address_to_use(): void
    {
        // The address is the username, and a person can hold more than one — "sign in" alone does
        // not say which.
        User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ops@acme.test',
            'password' => bcrypt('whatever'), 'status' => 1,
            'company_id' => $this->project->company_id,
        ]);

        $invite = $this->invite($this->client('ops@acme.test'));

        $this->post(route('project-management.invite.accept', ['token' => $invite->plainToken]))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'ops@acme.test'));
    }

    // ── When the post office is down ─────────────────────────────────────────

    public function test_a_failed_email_still_issues_a_usable_invitation(): void
    {
        /*
         * Reported: a client was added, the invitation sent, and the email never arrived. Sending
         * used to throw, which left the person who issued it looking at an error — with a live,
         * perfectly valid link they could not see and no way to pass on. On an installation whose
         * SMTP is refusing connections that is every invitation, and the portal becomes unreachable
         * for reasons that have nothing to do with the portal.
         */
        Mail::shouldReceive('to')->andThrow(
            new \Symfony\Component\Mailer\Exception\TransportException('Connection timed out.')
        );

        $member = $this->client();

        $invite = app(MemberInviteService::class)->send($member, null, $this->manager->id);

        $this->assertFalse($invite->delivered, 'The attempt failed and says so.');
        $this->assertTrue($invite->isOpen(), 'The invitation itself is untouched by the post office.');

        // And the link works, which is the whole point of handing it over.
        $this->get(route('project-management.invite.show', ['token' => $invite->plainToken]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('valid', true));
    }

    public function test_the_link_comes_back_so_it_can_be_passed_on_by_hand(): void
    {
        Mail::fake();

        $member = $this->client();

        $this->actingAs($this->manager)
            ->post(route('project-management.members.invite', [$this->project->uuid, $member->uuid]), [
                'email' => 'ops@acme.test',
            ])
            ->assertSessionHas('inviteLink', fn ($link) =>
                str_contains($link['url'], '/project-management/invite/')
                && $link['email'] === 'ops@acme.test'
                && $link['delivered'] === true);
    }

    public function test_a_member_who_already_has_a_login_can_be_sent_another(): void
    {
        // The affordance used to disappear the moment a login existed, which is exactly when
        // somebody stuck at the sign-in screen needs it.
        Mail::fake();

        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ops@acme.test',
            'password' => bcrypt('x'), 'status' => 1, 'company_id' => $this->project->company_id,
        ]);

        $member = $this->client('ops@acme.test');
        $member->update(['user_id' => $user->id]);

        $this->actingAs($this->manager)
            ->post(route('project-management.members.invite', [$this->project->uuid, $member->uuid]), [
                'email' => 'ops@acme.test',
            ])
            ->assertSessionHasNoErrors();
    }
}
