<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Services\StatementsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Inertia\Inertia;
use Inertia\Response;

class StatementsController extends Controller
{
    public function __construct(private StatementsService $statements)
    {
    }

    private function range(Request $request): array
    {
        $from = ($request->date('from') ?? Carbon::now()->startOfYear())->toDateString();
        $to   = ($request->date('to') ?? Carbon::now()->endOfMonth())->toDateString();

        return [$from, $to];
    }

    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $tab = array_key_exists($request->input('tab'), StatementsService::TABS) ? $request->input('tab') : 'profit_loss';

        return Inertia::render('Modules/Finance/Accounting/Statements', [
            'tabs'   => collect(StatementsService::TABS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'active' => $tab,
            'range'  => ['from' => $from, 'to' => $to],
            'report' => $this->statements->report($tab, $from, $to),
        ]);
    }

    public function export(Request $request): SymfonyResponse
    {
        [$from, $to] = $this->range($request);
        $tab = array_key_exists($request->input('tab'), StatementsService::TABS) ? $request->input('tab') : 'profit_loss';
        $report = $this->statements->report($tab, $from, $to);
        $slug = str($report['title'])->slug();

        if ($request->input('format') === 'pdf') {
            return Pdf::loadView('pdf.finance.statement-report', ['report' => $report, 'from' => $from, 'to' => $to])
                ->download("{$slug}.pdf");
        }

        // Excel (xlsx)
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setCellValue('A1', $report['title'] . "  ({$from} → {$to})");
        $headRow = 3;
        foreach ($report['columns'] as $i => $c) {
            $sheet->setCellValueByColumnAndRow($i + 1, $headRow, $c['label']);
        }
        $r = $headRow + 1;
        foreach ($report['rows'] as $row) {
            foreach ($report['columns'] as $i => $c) {
                $sheet->setCellValueByColumnAndRow($i + 1, $r, $row[$c['key']] ?? '');
            }
            $r++;
        }
        if (! empty($report['totals'])) {
            foreach ($report['columns'] as $i => $c) {
                $sheet->setCellValueByColumnAndRow($i + 1, $r, $report['totals'][$c['key']] ?? '');
            }
        }

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, "{$slug}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
