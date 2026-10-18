<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Core\User\Models\User;
use App\Modules\Finance\Tax\Models\TaxReturnForm;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use App\Modules\Finance\Tax\Support\TaxSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3.1 Tax Administration: the company's settings, who holds which tax permission, and the
 * controlled configuration (due-date rules, return-form versions) shown read-only — those change
 * through a reviewed release, not a form.
 */
class TaxAdminController extends TaxController
{
    public function index(Request $request, TaxSettings $settings): Response
    {
        $this->allow(TaxPermissions::ADMIN, 'admin.view');
        $companyId = (int) $request->user()->company_id;

        $users = User::query()->with(['person', 'roles.permissions', 'permissions'])->where('company_id', $companyId)->get()
            ->map(function (User $u) {
                $perms = $u->getAllPermissions()->pluck('name')->filter(fn ($p) => str_starts_with($p, 'tax.'))->values();

                return ['id' => $u->id, 'name' => app(\App\Modules\Finance\Tax\Services\TaxAudit::class)->nameOf($u), 'permissions' => $perms->all(),
                    'roles' => $u->roles->pluck('name')->filter(fn ($r) => str_starts_with($r, 'Tax '))->values()->all()];
            })->filter(fn ($u) => $u['permissions'] !== [])->sortBy('name')->values();

        return Inertia::render('Modules/Finance/Tax/Admin', [
            'settings' => collect(TaxSettings::KEYS)->map(fn ($def, $key) => ['key' => $key, 'label' => $def[1], 'value' => $settings->get($key, $companyId),
                'default' => $settings->default($key)])->values(),
            'permissions' => collect(TaxPermissions::all())->values(),
            'users' => $users,
            'taxTypes' => TaxType::orderBy('id')->get(['code', 'name', 'frequencies', 'filing_due_rule', 'payment_due_rule', 'has_return', 'has_payment']),
            'forms' => TaxReturnForm::orderBy('code')->orderByDesc('version')->get(['code', 'version', 'name', 'effective_from', 'effective_to', 'source_reference']),
            // §19 / §21: integration health — what is waiting and what failed.
            'events' => [
                'counts' => \Illuminate\Support\Facades\DB::table('tax_events')->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
                'failed' => \Illuminate\Support\Facades\DB::table('tax_events')->where('status', 'failed')->orderByDesc('id')->limit(20)
                    ->get(['id', 'name', 'attempts', 'last_error', 'created_at']),
            ],
        ]);
    }

    public function retryEvent(int $event, \App\Modules\Finance\Tax\Support\TaxEvents $events): RedirectResponse
    {
        $this->allow(TaxPermissions::ADMIN, 'admin.events');
        $events->retry($event);

        return back()->with('success', 'The event will be delivered again on the next run.');
    }

    public function update(Request $request, TaxSettings $settings): RedirectResponse
    {
        $this->allow(TaxPermissions::ADMIN, 'admin.settings');
        $rules = collect(TaxSettings::KEYS)->mapWithKeys(fn ($def, $key) => [$key => 'sometimes|' . $def[2]])->all();
        $d = $request->validate($rules + ['reminder_days.*' => 'integer|min:1|max:90', 'reason' => 'required|string|min:5|max:1000']);
        $reason = $d['reason'];
        unset($d['reason']);

        return $this->attempt(fn () => $settings->update($d, $reason), 'Settings saved.');
    }
}
