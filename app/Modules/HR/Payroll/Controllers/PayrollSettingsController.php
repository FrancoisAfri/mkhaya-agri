<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollSetting;
use App\Modules\Policies\Models\EventRuleMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollSettingsController extends Controller
{
    /**
     * The pay cycles this payroll can run, in the one spelling the tax engine understands.
     *
     * Kept as a constant so the screen, the validator and the engine cannot drift apart again —
     * that drift is exactly what made the fortnightly option unsaveable and, once saved by other
     * means, taxed as if it were monthly.
     */
    public const PAY_FREQUENCIES = ['weekly', 'fortnightly', 'monthly'];

    public function index(): Response
    {
        $settings = PayrollSetting::first() ?? new PayrollSetting();

        $rules = EventRuleMap::with(['rule.conditions'])
            ->where('module', 'Payroll')
            ->where('active', true)
            ->get()
            ->map(fn ($map) => [
                'id'             => $map->id,
                'action_pattern' => $map->action_pattern,
                'rule'           => $map->rule ? [
                    'id'               => $map->rule->id,
                    'name'             => $map->rule->name,
                    'description'      => $map->rule->description,
                    'severity'         => $map->rule->severity,
                    'action_on_breach' => $map->rule->action_on_breach,
                    'active'           => $map->rule->active,
                    'conditions'       => $map->rule->conditions ?? [],
                ] : null,
            ]);

        return Inertia::render('Modules/HR/Payroll/Settings/Index', [
            'settings' => $settings,
            'rules'    => $rules,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'currency'           => 'nullable|string|max:10',
            'pay_frequency'      => 'nullable|string|in:' . implode(',', self::PAY_FREQUENCIES),
            'pay_day'            => 'nullable|integer|min:1|max:31',
            'overtime_rate'      => 'nullable|numeric|min:1|max:5',
            'employer_name'         => 'nullable|string|max:255',
            'employer_trading_name' => 'nullable|string|max:255',
            'employer_address'      => 'nullable|string|max:500',
            'paye_reference'        => 'nullable|string|max:30',
            'uif_reference'         => 'nullable|string|max:30',
            'sdl_reference'         => 'nullable|string|max:30',
            'employer_contact'      => 'nullable|string|max:255',
            'paye_enabled'       => 'nullable|boolean',
            'uif_enabled'        => 'nullable|boolean',
            'sdl_enabled'        => 'nullable|boolean',
            'uif_employee_rate'  => 'nullable|numeric|min:0|max:1',
            'uif_employer_rate'  => 'nullable|numeric|min:0|max:1',
            'sdl_rate'           => 'nullable|numeric|min:0|max:1',
            'payroll_budget'     => 'nullable|numeric|min:0',
            'settings_json'      => 'nullable|array',
            'is_active'          => 'nullable|boolean',
        ]);

        PayrollSetting::updateOrCreate(
            ['id' => PayrollSetting::first()?->id ?? 0],
            $validated
        );

        return back()->with('success', 'Payroll settings updated successfully.');
    }
}
