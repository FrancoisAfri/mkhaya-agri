<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinReport;
use App\Modules\Finance\Accounting\Services\ReportBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReportBuilderController extends Controller
{
    public function __construct(private ReportBuilderService $builder)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/ReportBuilder', [
            'catalog' => $this->builder->catalog(),
            'saved'   => FinReport::where(fn ($q) => $q->where('owner_id', Auth::id())->orWhere('is_shared', true))
                ->orderBy('name')->get()->map(fn (FinReport $r) => [
                    'id' => $r->id, 'name' => $r->name, 'source' => $r->source,
                    'dimensions' => $r->dimensions, 'measures' => $r->measures, 'filters' => $r->filters,
                    'is_shared' => $r->is_shared, 'mine' => $r->owner_id === Auth::id(),
                ]),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        return response()->json($this->builder->run($this->definition($request)));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->definition($request);
        FinReport::create($data + [
            'name'      => $request->validate(['name' => 'required|string|max:150'])['name'],
            'is_shared' => (bool) $request->boolean('is_shared'),
            'owner_id'  => Auth::id(),
        ]);

        return back()->with('success', 'Report saved.');
    }

    public function destroy(FinReport $report): RedirectResponse
    {
        abort_unless($report->owner_id === Auth::id(), 403);
        $report->delete();

        return back()->with('success', 'Report deleted.');
    }

    public function export(Request $request): StreamedResponse
    {
        $result = $this->builder->run($this->definition($request));
        $name = $request->input('name', 'report');

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($result['columns'], 'label'));
            foreach ($result['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c['key']] ?? '', $result['columns']));
            }
            fclose($out);
        }, str($name)->slug() . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function definition(Request $request): array
    {
        return $request->validate([
            'source'        => 'required|in:gl,invoices',
            'dimensions'    => 'array',
            'dimensions.*'  => 'string',
            'measures'      => 'array',
            'measures.*'    => 'string',
            'filters'       => 'array',
        ]);
    }
}
