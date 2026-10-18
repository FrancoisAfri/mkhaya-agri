<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Services\TaxReportService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §20.2 standard reports: a preview of each, and the full export with its heading block (§20.3). */
class TaxReportController extends TaxController
{
    private const PREVIEW = 50;

    public function __construct(private readonly TaxReportService $reports)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(TaxPermissions::VIEW);
        [$key, $f] = $this->params($request);
        [$headers, $rows] = $this->service($f)->dataset($key, self::PREVIEW);

        return Inertia::render('Modules/Finance/Tax/Reports', [
            'reports' => collect(TaxReportService::REPORTS)->map(fn ($r, $k) => ['key' => $k, 'title' => $r[0], 'description' => $r[1],
                'restricted' => $k === 'audit' && ! $request->user()->can(TaxPermissions::VIEW_AUDIT)])->values(),
            'current' => $key,
            'headers' => $headers,
            'rows' => is_array($rows) ? $rows : iterator_to_array($rows, false),
            'preview' => self::PREVIEW,
            'filters' => $f,
        ] + $this->businessProps($f['business']));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->allow(TaxPermissions::VIEW);
        [$key, $f] = $this->params($request);
        $service = $this->service($f);
        [$headers, $rows] = $service->dataset($key);

        return $this->csv("tax-{$key}-" . now()->format('Ymd-His') . '.csv', $headers, $rows, $service->meta($key));
    }

    private function params(Request $request): array
    {
        $d = $request->validate(['report' => ['nullable', 'in:' . implode(',', array_keys(TaxReportService::REPORTS))],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'tax_type' => ['nullable', 'string', 'max:10']]);
        $key = $d['report'] ?? 'vat_register';
        if ($key === 'audit') {
            $this->allow(TaxPermissions::VIEW_AUDIT, 'report.audit');
        }

        return [$key, ['business' => $this->businessFilter(), 'from' => $d['from'] ?? null, 'to' => $d['to'] ?? null, 'tax_type' => $d['tax_type'] ?? null]];
    }

    private function service(array $f): TaxReportService
    {
        return $this->reports->filter($f['business'], $f['from'], $f['to'], $f['tax_type']);
    }
}
