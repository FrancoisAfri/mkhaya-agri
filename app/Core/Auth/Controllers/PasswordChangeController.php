<?php

namespace App\Core\Auth\Controllers;

use App\Core\User\Models\User;
use App\Core\UserManagement\Models\PasswordHistory;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Setup\Models\CompanyIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PasswordChangeController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Pages/Auth/PasswordChange');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        // No reuse of last 5 passwords
        $reused = PasswordHistory::where('user_id', $user->id)
            ->latest()
            ->take(5)
            ->get()
            ->contains(fn ($h) => Hash::check($request->password, $h->password));

        if ($reused) {
            return back()->withErrors(['password' => 'You cannot reuse a recent password. Please choose a different one.']);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'password_changed_at' => now(),
        ]);

        PasswordHistory::createPassword($user->id, $user->password);

        // Reset expiry based on company policy
        $months = CompanyIdentity::first()?->password_expiring_month;
        if ($months && $months > 0) {
            PasswordSecurity::addExpiryDateFromMonths($user->id, $months);
        } else {
            // Clear force_change flag and remove expiry
            PasswordSecurity::updateOrCreate(
                ['user_id' => $user->id],
                ['force_change' => false, 'password_expiry_at' => null, 'expires_at' => null]
            );
        }

        return redirect()->route('dashboard')->with('success', 'Password changed successfully.');
    }
}
