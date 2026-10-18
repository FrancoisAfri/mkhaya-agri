<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinGroup;
use App\Modules\Finance\Accounting\Models\FinGroupMember;
use App\Modules\Finance\Accounting\Services\ConsolidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ConsolidationController extends Controller
{
    public function __construct(private ConsolidationService $consolidation)
    {
    }

    public function index(Request $request): Response
    {
        $groups = FinGroup::withCount('members')->orderBy('name')->get()
            ->map(fn (FinGroup $g) => ['id' => $g->id, 'name' => $g->name, 'base_currency' => $g->base_currency, 'members_count' => $g->members_count]);

        $selected = $request->integer('group') ?: ($groups[0]['id'] ?? null);
        $detail = null;
        $result = null;
        if ($selected && ($group = FinGroup::with('members')->find($selected))) {
            $detail = [
                'id' => $group->id, 'name' => $group->name, 'base_currency' => $group->base_currency,
                'intercompany_codes' => $group->intercompany_codes ?? [],
                'members' => $group->members->map(fn (FinGroupMember $m) => [
                    'id' => $m->id, 'company_id' => $m->company_id, 'name' => $m->name,
                    'ownership_pct' => (float) $m->ownership_pct, 'fx_rate' => (float) $m->fx_rate,
                    'fx_method' => $m->fx_method, 'is_parent' => $m->is_parent,
                ]),
            ];
            if ($group->members->isNotEmpty()) {
                $result = $this->consolidation->consolidate($group, $request->input('as_of'));
            }
        }

        return Inertia::render('Modules/Finance/Accounting/Consolidation', [
            'groups'    => $groups,
            'selected'  => $selected,
            'detail'    => $detail,
            'result'    => $result,
            'companies' => DB::table('company_identities')->get(['id', 'company_name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->company_name]),
        ]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        FinGroup::create($this->groupRules($request) + ['created_by' => Auth::id()]);

        return back()->with('success', 'Group created.');
    }

    public function updateGroup(Request $request, FinGroup $group): RedirectResponse
    {
        $group->update($this->groupRules($request));

        return back()->with('success', 'Group updated.');
    }

    public function addMember(Request $request, FinGroup $group): RedirectResponse
    {
        $data = $request->validate([
            'company_id'    => 'required|integer',
            'name'          => 'required|string|max:150',
            'ownership_pct' => 'nullable|numeric|min:0|max:100',
            'fx_rate'       => 'nullable|numeric|min:0',
            'fx_method'     => 'nullable|in:closing,average,historic',
            'is_parent'     => 'boolean',
        ]);
        $group->members()->updateOrCreate(['company_id' => $data['company_id']], $data);

        return back()->with('success', 'Member added.');
    }

    public function removeMember(FinGroupMember $member): RedirectResponse
    {
        $member->delete();

        return back()->with('success', 'Member removed.');
    }

    private function groupRules(Request $request): array
    {
        return $request->validate([
            'name'                 => 'required|string|max:150',
            'base_currency'        => 'nullable|string|size:3',
            'intercompany_codes'   => 'array',
            'intercompany_codes.*' => 'string|max:20',
        ]);
    }
}
