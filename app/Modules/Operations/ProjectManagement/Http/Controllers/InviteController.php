<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\MemberInvite;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\MemberInviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issuing, withdrawing and taking up a portal invitation.
 *
 * Two halves with very different exposure. `store` and `destroy` sit inside the module's
 * authenticated group and are authorised by the same `manageMembers` policy that governs adding
 * somebody to a project — if you may put a client on a project, you may send them the link. `show`
 * and `accept` are reached by someone with no account at all, so they are mounted outside the auth
 * group and carry nothing but the token.
 */
class InviteController extends Controller
{
    public function __construct(private readonly MemberInviteService $invites)
    {
    }

    /** Send (or re-send) an invitation to a member. */
    public function store(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('manageMembers', $project);

        $member = ProjectMember::where('project_id', $project->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        try {
            $invite = $this->invites->send($member, $data['email'] ?? null, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        /*
         * The link comes back either way, so it can be copied and passed on by hand.
         *
         * An email that does not arrive used to be the end of it: the invitation existed, was
         * valid, and nobody could see it. Handing the link to the person who issued it costs
         * nothing — they are the one inviting — and it is the difference between a bad SMTP
         * configuration slowing somebody down and stopping them.
         */
        return back()->with('inviteLink', [
            'url'       => $this->invites->linkFor($invite),
            'email'     => $invite->email,
            'name'      => $member->display_name,
            'delivered' => $invite->delivered,
            'expires'   => $invite->expires_at?->toDateString(),
        ]);
    }

    public function destroy(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('manageMembers', $project);

        $invite = MemberInvite::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        try {
            $this->invites->revoke($invite, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['invite' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.invite_revoked'));
    }

    /**
     * The landing page for an invitation link.
     *
     * An unusable invitation still renders this page, carrying the reason. Redirecting to a login
     * screen instead would leave somebody staring at a form wondering why their link "did
     * nothing", which is the support call this page exists to prevent.
     */
    public function show(string $token): Response
    {
        $invite = $this->invites->findByToken($token);

        if (! $invite) {
            return Inertia::render('Modules/ProjectManagement/Portal/Accept', [
                'valid'  => false,
                'reason' => 'project_management.invites.not_found',
            ]);
        }

        $reason = $invite->refusalReason();

        if ($reason !== null) {
            return Inertia::render('Modules/ProjectManagement/Portal/Accept', [
                'valid'  => false,
                'reason' => $reason,
            ]);
        }

        return Inertia::render('Modules/ProjectManagement/Portal/Accept', [
            'valid'   => true,
            'token'   => $token,
            'email'   => $invite->email,
            'project' => $invite->project?->name,
            'role'    => $invite->role_key,
            // Drives whether a password is asked for. An address that already has an account is
            // never given a new password here — see MemberInviteService::accept.
            'hasAccount' => User::withoutGlobalScope('company')->where('email', $invite->email)->exists(),
            'expires' => $invite->expires_at->toDateString(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invite = $this->invites->findByToken($token);

        if (! $invite || ! $invite->isOpen()) {
            return redirect()->route('project-management.invite.show', ['token' => $token]);
        }

        $needsPassword = ! User::withoutGlobalScope('company')->where('email', $invite->email)->exists();

        $data = $request->validate([
            'password' => $needsPassword
                ? ['required', 'confirmed', Password::defaults()]
                : ['nullable'],
        ]);

        try {
            $result = $this->invites->accept($invite, $data['password'] ?? null);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        // Only the account this invitation just created is signed in. An address that already had
        // one proves who it belongs to at the login screen, not by holding a link.
        if ($result['created']) {
            Auth::login($result['user']);

            return redirect()->route('project-management.portal')
                ->with('success', __('project_management.flash.invite_accepted'));
        }

        /*
         * Already signed in as the very person the invitation was for.
         *
         * Sending them to the login screen was the reported failure: somebody following the link
         * from a browser where they were already signed in was bounced to a login they did not
         * need, and the invitation — single use — was spent on the way. Nothing said what had
         * happened, so it read as the link being broken.
         */
        if (Auth::id() === $result['user']->id) {
            return redirect()->route('project-management.portal')
                ->with('success', __('project_management.flash.invite_accepted'));
        }

        // Naming the address matters: it is the username, and it is not necessarily the one they
        // would have guessed — a person can hold more than one.
        return redirect()->route('login')
            ->with('success', __('project_management.flash.invite_linked', ['email' => $invite->email]));
    }
}
