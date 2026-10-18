<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaSettingsController extends Controller
{
    private array $settingKeys = [
        'default_cycle_type',
        'allow_self_review',
        'allow_peer_review',
        'require_manager_approval',
        'enable_ai_insights',
        'notify_reviewee_on_submission',
        'notify_manager_on_self_review',
    ];

    public function index(): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/Settings/Index', [
            'settings' => PaSetting::all_settings(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_cycle_type'            => ['sometimes', 'in:annual,semi_annual,quarterly,monthly,probation,custom'],
            'allow_self_review'             => ['sometimes', 'boolean'],
            'allow_peer_review'             => ['sometimes', 'boolean'],
            'require_manager_approval'      => ['sometimes', 'boolean'],
            'enable_ai_insights'            => ['sometimes', 'boolean'],
            'notify_reviewee_on_submission' => ['sometimes', 'boolean'],
            'notify_manager_on_self_review' => ['sometimes', 'boolean'],
        ]);

        foreach ($data as $key => $value) {
            PaSetting::set($key, $value ? '1' : (is_bool($value) ? '0' : $value));
        }

        return back()->with('success', 'Settings saved.');
    }
}
