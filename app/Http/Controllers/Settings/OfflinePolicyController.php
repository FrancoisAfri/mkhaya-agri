<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\OfflinePolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfflinePolicyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pages/Settings/OfflinePolicy', [
            'policy' => OfflinePolicy::current()->toSharedArray(),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'enabled'             => 'required|boolean',
            'max_offline_minutes' => 'required|integer|min:15|max:2880', // 15 min – 48 h
            'warn_before_minutes' => 'required|integer|min:5|max:60',
            'lockout_message'     => 'nullable|string|max:500',
        ]);

        // Warn period must be less than max
        if ($data['warn_before_minutes'] >= $data['max_offline_minutes']) {
            return back()->withErrors([
                'warn_before_minutes' => 'Warning period must be shorter than the max offline duration.',
            ]);
        }

        OfflinePolicy::current()->update($data);

        return back()->with('success', 'Offline policy updated.');
    }
}
