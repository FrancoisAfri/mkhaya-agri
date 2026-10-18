<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\WarehousePolicies;
use App\Modules\Operations\WarehouseManagement\Support\WarehousePolicySchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-240/241: a warehouse's policies -- checked, drafted, activated by name, and restorable. */
class PolicyController extends Controller
{
    use RefusesPolitely;

    public function show(Request $request, string $uuid, WarehousePolicies $policies): Response
    {
        $w = FinWarehouse::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $w);

        return Inertia::render('Modules/Warehouse/Layout/Policies', [
            'warehouse' => ['id' => $w->uuid, 'code' => $w->code, 'name' => $w->name],
            'current' => $policies->current($w),
            'rules' => WarehousePolicySchema::RULES,
            'versions' => DB::table('wms_policy_versions as v')->leftJoin('users as c', 'c.id', '=', 'v.created_by')->leftJoin('users as a', 'a.id', '=', 'v.activated_by')
                ->where('v.warehouse_id', $w->id)->orderByDesc('v.version')->limit(50)->get(['v.*', 'c.email as by', 'a.email as activated_by_email'])
                ->map(fn ($v) => ['id' => $v->id, 'version' => (int) $v->version, 'status' => $v->status, 'changes' => json_decode($v->changes, true), 'note' => $v->note,
                    'by' => $v->by, 'at' => Carbon::parse($v->created_at)->toIso8601String(), 'activated_by' => $v->activated_by_email,
                    'activated_at' => $v->activated_at ? Carbon::parse($v->activated_at)->toIso8601String() : null]),
            'check' => session('policy_check'),
            'can' => ['manage' => $request->user()->can('update', $w)],
        ]);
    }

    public function draft(Request $request, string $uuid, WarehousePolicies $policies): RedirectResponse
    {
        $w = FinWarehouse::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $w);
        $d = $request->validate(['policies' => ['required', 'array:'.implode(',', array_keys(WarehousePolicySchema::RULES))], 'note' => ['nullable', 'string', 'max:500'],
            'check_only' => ['boolean']]);
        if ($d['check_only'] ?? false) {
            [, $errors, $changes] = $policies->check($w, $d['policies']);

            return back()->with('policy_check', ['errors' => $errors, 'changes' => $changes]);
        }

        return $this->attempt(fn () => $policies->draft($w, $d['policies'], $request->user(), $d['note'] ?? null), 'Draft saved. Activate it when it is ready.');
    }

    public function act(Request $request, string $uuid, string $version, WarehousePolicies $policies): RedirectResponse
    {
        $w = FinWarehouse::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $w);
        $v = DB::table('wms_policy_versions')->where('warehouse_id', $w->id)->where('id', (int) $version)->firstOrFail();
        $d = $request->validate(['action' => ['required', Rule::in(['activate', 'discard', 'restore'])]]);

        return $this->attempt(fn () => match ($d['action']) {
            'activate' => $policies->activate($v, $request->user()),
            'discard' => $policies->discard($v),
            'restore' => $policies->restore($v, $request->user()),
        }, match ($d['action']) { 'activate' => 'Policies in force.', 'discard' => 'Draft discarded.', default => 'Earlier version restored as a new version.' });
    }
}
