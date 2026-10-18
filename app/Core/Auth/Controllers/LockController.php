<?php

namespace App\Core\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Core\Auth\Support\LandingPage;
use App\Core\User\Models\User;
use App\Http\Middleware\AuthLock;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class LockController extends Controller
{
    /**
     * Lock the session and redirect to the lock screen.
     */
    public function lock(Request $request)
    {
        session([AuthLock::SESSION_LOCKED_KEY => true]);
        session()->forget('last_activity');

        return redirect()->route('lock.screen');
    }

    /**
     * Show the lock screen.
     * The user is still authenticated — we just need their name/avatar.
     */
    public function show(): Response
    {
        $user = Auth::user();

        return Inertia::render('Pages/Auth/LockScreen', [
            'firstName' => explode(' ', self::displayName($user))[0],
            'avatar' => $user->avatar_url ?? asset('images/default-avatar.png'),
            'companyLogo' => optional($user->company)->logo,
        ]);
    }

    /**
     * Verify the password and unlock the session.
     */
    public function unlock(Request $request)
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if (! Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'password' => __('The password you entered is incorrect.'),
            ]);
        }

        // Clear the lock flag and restart the activity timer
        session()->forget(AuthLock::SESSION_LOCKED_KEY);
        // Make the constant accessible — or just use the string key directly:
        // session()->forget('session_locked');
        session(['last_activity' => now()->timestamp]);

        /*
         * Back to where this person belongs, not to the dashboard.
         *
         * This was `intended('/')`, and for a project client that is the internal application: they
         * hold none of the dashboard permissions, so unlocking their own session ended in a 403.
         * Locking the screen is not a change of who somebody is, and it should not move them.
         *
         * LandingPage is the same resolver login already uses, so the two cannot disagree about
         * where a client goes — which is how this got missed: logging in worked, unlocking did not,
         * and the difference was one hard-coded slash.
         */
        return redirect()->intended(LandingPage::for($user));
    }

    /**
     * The name to greet somebody by.
     *
     * `$user->name` falls back to the email address when there is no `hr_people` record — and a
     * project client has no employment record by design, because they do not work here. So the lock
     * screen greeted them with their own email address.
     *
     * Their name is on the project membership, which is where somebody typed it when they were
     * invited. One query, on a page that shows one user.
     */
    private static function displayName(?User $user): string
    {
        if (! $user) {
            return '';
        }

        if (filled($user->first_name)) {
            return (string) $user->first_name;
        }

        $member = ProjectMember::query()
            ->where('user_id', $user->id)
            ->whereNotNull('display_name')
            ->orderByDesc('id')
            ->value('display_name');

        return filled($member) ? (string) $member : (string) $user->name;
    }
}
