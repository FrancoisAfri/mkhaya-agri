<?php

namespace App\Http\Controllers\Settings;

use App\Models\AppSetting;
use App\Models\UserThemeSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ThemeSettingController
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Pages/Settings/Theme', [
            'userSettings'   => UserThemeSetting::where('user_id', $user->id)->value('settings') ?? (object) [],
            'globalSettings' => AppSetting::get('global_theme', (object) []),
            'isSuperAdmin'   => $user->hasRole('administrator'),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'theme'                  => ['nullable', 'string', 'in:light,dark,system'],
            'primaryColor'           => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'skin'                   => ['nullable', 'string', 'in:default,bordered'],
            'contentWidth'           => ['nullable', 'string', 'in:fluid,boxed'],
            'contentLayoutNav'       => ['nullable', 'string', 'in:vertical,horizontal'],
            'isVerticalNavCollapsed' => ['nullable', 'boolean'],
            'isVerticalNavSemiDark'  => ['nullable', 'boolean'],
            'navbarBlur'             => ['nullable', 'boolean'],
        ]);

        UserThemeSetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['settings' => $validated]
        );

        return back()->with('success', 'Theme settings saved.');
    }

    public function updateGlobal(Request $request)
    {
        abort_unless($request->user()->hasRole('administrator'), 403);

        $validated = $request->validate([
            'theme'                  => ['nullable', 'string', 'in:light,dark,system'],
            'primaryColor'           => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'skin'                   => ['nullable', 'string', 'in:default,bordered'],
            'contentWidth'           => ['nullable', 'string', 'in:fluid,boxed'],
            'contentLayoutNav'       => ['nullable', 'string', 'in:vertical,horizontal'],
            'isVerticalNavCollapsed' => ['nullable', 'boolean'],
            'isVerticalNavSemiDark'  => ['nullable', 'boolean'],
            'navbarBlur'             => ['nullable', 'boolean'],
        ]);

        AppSetting::set('global_theme', $validated);

        return back()->with('success', 'Global theme applied to all users.');
    }
}
