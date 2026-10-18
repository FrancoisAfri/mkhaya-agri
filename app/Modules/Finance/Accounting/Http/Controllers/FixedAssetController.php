<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinAsset;
use App\Modules\Finance\Accounting\Services\FixedAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetController extends Controller
{
    public function __construct(private FixedAssetService $assets)
    {
    }

    public function index(): Response
    {
        $assets = FinAsset::orderByDesc('id')->limit(500)->get()->map(fn (FinAsset $a) => [
            'id' => $a->id, 'tag' => $a->tag, 'name' => $a->name, 'category' => $a->category,
            'acquisition_date' => $a->acquisition_date?->toDateString(), 'cost' => (float) $a->cost,
            'accumulated_depreciation' => (float) $a->accumulated_depreciation, 'book_value' => $a->bookValue(),
            'method' => $a->method, 'status' => $a->status, 'residual_value' => (float) $a->residual_value,
            'useful_life_months' => $a->useful_life_months, 'annual_rate' => $a->annual_rate ? (float) $a->annual_rate : null,
            'parent_id' => $a->parent_id, 'disposal_date' => $a->disposal_date?->toDateString(),
            'disposal_proceeds' => $a->disposal_proceeds !== null ? (float) $a->disposal_proceeds : null,
        ]);

        $active = $assets->where('status', 'active');

        return Inertia::render('Modules/Finance/Accounting/FixedAssets', [
            'assets'  => $assets->values(),
            'methods' => FinAsset::METHODS,
            'summary' => [
                'count'      => $active->count(),
                'cost'       => round($active->sum('cost'), 2),
                'accum'      => round($active->sum('accumulated_depreciation'), 2),
                'book_value' => round($active->sum('book_value'), 2),
            ],
            'accounts' => FinAccount::postable()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'label' => "{$a->code} · {$a->name}"]),
            'parents' => $assets->where('status', 'active')->map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:150',
            'tag'                => 'nullable|string|max:60',
            'category'           => 'nullable|string|max:80',
            'parent_id'          => 'nullable|integer|exists:fin_assets,id',
            'acquisition_date'   => 'required|date',
            'cost'               => 'required|numeric|min:0',
            'residual_value'     => 'nullable|numeric|min:0',
            'useful_life_months' => 'required_if:method,straight_line|nullable|integer|min:1',
            'method'             => 'required|in:straight_line,reducing_balance',
            'annual_rate'        => 'required_if:method,reducing_balance|nullable|numeric|min:0|max:100',
            'asset_account_id'   => 'nullable|integer|exists:fin_accounts,id',
            'accum_account_id'   => 'nullable|integer|exists:fin_accounts,id',
            'expense_account_id' => 'nullable|integer|exists:fin_accounts,id',
            'post_acquisition'   => 'boolean',
            'funding_account'    => 'nullable|string',
        ]);

        try {
            $this->assets->create($data, (bool) ($data['post_acquisition'] ?? false), $data['funding_account'] ?? null);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['asset' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Asset registered.');
    }

    public function depreciate(Request $request): RedirectResponse
    {
        $data = $request->validate(['period' => 'nullable|date']);
        $result = $this->assets->run(Carbon::parse($data['period'] ?? now()));

        return back()->with('success', "Depreciated {$result['count']} asset(s) — total " . number_format($result['total'], 2) . " for {$result['period']}.");
    }

    public function dispose(Request $request, FinAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'disposal_date'    => 'required|date',
            'proceeds'         => 'required|numeric|min:0',
            'proceeds_account' => 'nullable|string',
        ]);

        try {
            $this->assets->dispose($asset, Carbon::parse($data['disposal_date']), (float) $data['proceeds'], $data['proceeds_account'] ?? null);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['asset' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Asset disposed.');
    }
}
