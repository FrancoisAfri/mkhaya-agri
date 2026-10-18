<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\Evidence;
use App\Modules\Operations\WarehouseManagement\Models\LossRecovery;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Services\EvidenceLocker;
use App\Modules\Operations\WarehouseManagement\Services\LossControl;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-081: the loss register, recoveries, and evidence files. */
class LossController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request, LossControl $losses): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $warehouse = $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail() : null;

        return Inertia::render('Modules/Warehouse/Losses/Register', [
            'register' => $losses->register($warehouse?->id, $from, $to),
            'recoveries' => LossRecovery::query()->with('adjustment:id,uuid,number,value_estimate')->latest('id')->limit(100)->get()
                ->map(fn (LossRecovery $r) => ['id' => $r->uuid, 'adjustment' => $r->adjustment?->number, 'kind' => $r->kind, 'claim_reference' => $r->claim_reference,
                    'claimed' => (float) $r->amount_claimed, 'recovered' => (float) $r->amount_recovered, 'status' => $r->status, 'notes' => $r->notes,
                    'created_at' => $r->created_at?->toIso8601String()]),
            'filters' => ['from' => $from, 'to' => $to, 'warehouse' => $warehouse?->uuid],
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
            'kinds' => LossRecovery::KINDS,
            'can' => ['losses' => $request->user()->can(RoleCatalog::LOSSES)],
        ]);
    }

    public function openRecovery(Request $request, LossControl $losses): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::LOSSES), 403);
        $data = $request->validate(['adjustment' => ['required', 'string'], 'kind' => ['required', Rule::in(LossRecovery::KINDS)],
            'claim_reference' => ['nullable', 'string', 'max:120'], 'amount_claimed' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $adjustment = StockAdjustment::query()->where('uuid', $data['adjustment'])->firstOrFail();

        return $this->attempt(fn () => $losses->openRecovery($adjustment, $data, $request->user()), 'Recovery claim opened.');
    }

    public function progressRecovery(Request $request, string $uuid, LossControl $losses): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::LOSSES), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['SUBMITTED', 'SETTLED', 'DECLINED', 'WITHDRAWN'])],
            'amount_recovered' => ['nullable', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $losses->progress(LossRecovery::query()->where('uuid', $uuid)->firstOrFail(), $data['status'],
            isset($data['amount_recovered']) ? (float) $data['amount_recovered'] : null, $data['note'] ?? null), 'Claim updated.');
    }

    public function attachToAdjustment(Request $request, string $uuid, EvidenceLocker $locker): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::ADJUST_STOCK) || $request->user()->can(RoleCatalog::LOSSES), 403);
        $data = $request->validate(['file' => ['required', 'file', 'max:15360'], 'kind' => ['nullable', Rule::in(Evidence::KINDS)], 'caption' => ['nullable', 'string', 'max:255']]);
        $a = StockAdjustment::query()->where('uuid', $uuid)->firstOrFail();

        return $this->attempt(fn () => $locker->attach('ADJUSTMENT', $a->id, (int) $a->company_id, $request->file('file'), $request->user(),
            $data['kind'] ?? 'PHOTO', $data['caption'] ?? null), 'Evidence added.');
    }

    /** Evidence is a tenant file: the row's tenant scope is the gate, and the download is logged by the file store. */
    public function download(Request $request, string $uuid)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $e = Evidence::query()->where('uuid', $uuid)->firstOrFail();

        return Storage::disk('documents')->download($e->path, $e->original_name);
    }
}
