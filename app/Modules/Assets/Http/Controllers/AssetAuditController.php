<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\CompleteAssetAuditAction;
use App\Modules\Assets\Actions\ScanAuditLineAction;
use App\Modules\Assets\Actions\StartAssetAuditAction;
use App\Modules\Assets\Models\AssetAudit;
use App\Modules\Assets\Models\AssetAuditLine;
use App\Modules\Assets\Models\AssetLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AssetAuditController extends Controller
{
    public function index(Request $request): Response
    {
        $audits = AssetAudit::with('location')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'open'      => AssetAudit::where('status', AssetAudit::STATUS_OPEN)->count(),
            'completed' => AssetAudit::where('status', AssetAudit::STATUS_COMPLETED)->count(),
            'cancelled' => AssetAudit::where('status', AssetAudit::STATUS_CANCELLED)->count(),
            'total'     => AssetAudit::count(),
        ];

        return Inertia::render('Modules/Assets/Audits/Index', [
            'audits' => [
                'data' => $audits->map(fn (AssetAudit $a) => $this->auditSummary($a)),
                'meta' => [
                    'current_page' => $audits->currentPage(),
                    'last_page'    => $audits->lastPage(),
                    'total'        => $audits->total(),
                ],
                'links' => [
                    'prev' => $audits->previousPageUrl(),
                    'next' => $audits->nextPageUrl(),
                ],
            ],
            'stats'   => $stats,
            'filters' => $request->only('status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Assets/Audits/Create', [
            'locations' => AssetLocation::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name]),
        ]);
    }

    public function store(Request $request, StartAssetAuditAction $action): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer', 'exists:asset_locations,id'],
            'notes'       => ['nullable', 'string'],
        ]);

        $audit = $action->execute($data, Auth::id());

        return redirect()
            ->route('assets.audits.show', $audit->uuid)
            ->with('success', 'Audit started. Begin scanning assets.');
    }

    public function show(AssetAudit $audit): Response
    {
        $audit->load('location', 'startedBy');

        $lines = AssetAuditLine::with('asset:id,uuid,name,asset_number,barcode')
            ->where('audit_id', $audit->id)
            ->orderByDesc('scanned_at')
            ->orderBy('id')
            ->get();

        $mapLine = fn (AssetAuditLine $l) => [
            'id'           => $l->id,
            'uuid'         => $l->uuid,
            'status'       => $l->status,
            'scanned_code' => $l->scanned_code,
            'scanned_at'   => $l->scanned_at?->toISOString(),
            'notes'        => $l->notes,
            'asset'        => $l->asset ? [
                'uuid'         => $l->asset->uuid,
                'name'         => $l->asset->name,
                'asset_number' => $l->asset->asset_number,
                'barcode'      => $l->asset->barcode,
            ] : null,
        ];

        $expected = (int) $audit->expected_count;
        $found    = $lines->where('status', AssetAuditLine::STATUS_FOUND)->count();

        return Inertia::render('Modules/Assets/Audits/Show', [
            'audit'    => $this->auditSummary($audit),
            'progress' => [
                'expected' => $expected,
                'found'    => $found,
                'percent'  => $expected > 0 ? (int) round(($found / $expected) * 100) : 0,
            ],
            'lines' => [
                'found'      => $lines->where('status', AssetAuditLine::STATUS_FOUND)->values()->map($mapLine),
                'missing'    => $lines->whereIn('status', [AssetAuditLine::STATUS_EXPECTED, AssetAuditLine::STATUS_MISSING])->values()->map($mapLine),
                'unexpected' => $lines->where('status', AssetAuditLine::STATUS_UNEXPECTED)->values()->map($mapLine),
            ],
        ]);
    }

    public function scan(Request $request, AssetAudit $audit, ScanAuditLineAction $action)
    {
        if ($audit->status !== AssetAudit::STATUS_OPEN) {
            return back()->with('error', 'This audit is not open for scanning.');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:255'],
        ]);

        $outcome = $action->execute($audit, $data['code'], Auth::id());

        if ($request->wantsJson()) {
            return response()->json([
                'result' => $outcome['result'],
                'counts' => $outcome['counts'],
                'line'   => [
                    'uuid'         => $outcome['line']->uuid,
                    'status'       => $outcome['line']->status,
                    'scanned_code' => $outcome['line']->scanned_code,
                    'asset'        => $outcome['line']->asset ? [
                        'name'         => $outcome['line']->asset->name,
                        'asset_number' => $outcome['line']->asset->asset_number,
                    ] : null,
                ],
            ]);
        }

        return back()->with('success', 'Scan recorded: ' . $outcome['result']);
    }

    public function complete(AssetAudit $audit, CompleteAssetAuditAction $action): RedirectResponse
    {
        $action->execute($audit);

        return back()->with('success', 'Audit completed.');
    }

    public function destroy(AssetAudit $audit): RedirectResponse
    {
        if ($audit->status === AssetAudit::STATUS_COMPLETED) {
            return back()->with('error', 'A completed audit cannot be deleted.');
        }

        $audit->lines()->delete();
        $audit->delete();

        return redirect()
            ->route('assets.audits.index')
            ->with('success', 'Audit deleted.');
    }

    /**
     * @return array<string,mixed>
     */
    private function auditSummary(AssetAudit $audit): array
    {
        return [
            'uuid'             => $audit->uuid,
            'name'             => $audit->name,
            'status'          => $audit->status,
            'location'         => $audit->relationLoaded('location') && $audit->location
                ? ['id' => $audit->location->id, 'name' => $audit->location->name]
                : null,
            'started_by'       => $audit->relationLoaded('startedBy') && $audit->startedBy
                ? $audit->startedBy->name
                : null,
            'started_at'       => $audit->started_at?->toISOString(),
            'completed_at'     => $audit->completed_at?->toISOString(),
            'expected_count'   => (int) $audit->expected_count,
            'found_count'      => (int) $audit->found_count,
            'missing_count'    => (int) $audit->missing_count,
            'unexpected_count' => (int) $audit->unexpected_count,
            'notes'            => $audit->notes,
            'created_at'       => $audit->created_at?->toISOString(),
        ];
    }
}
