<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetJobCard;
use App\Modules\Fleet\Models\FleetJobCardNote;
use App\Modules\Fleet\Models\FleetJobCardPart;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Job card reporting: cards, notes and parts, each viewable on screen and
 * exportable as CSV (the print view is rendered by the front end).
 */
class FleetJobCardReportController extends Controller
{
    private const REPORTS = ['cards', 'notes', 'parts'];

    public function index(Request $request): Response
    {
        return Inertia::render('Modules/Fleet/JobCards/Reports', [
            'reports' => self::REPORTS,
            'filters' => $request->only(['from', 'to', 'status', 'vehicle_id']),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);

        return Inertia::render('Modules/Fleet/JobCards/Report', [
            'report'  => $report,
            'rows'    => $this->rows($report, $request)->paginate(50)->withQueryString(),
            'filters' => $request->only(['from', 'to', 'status', 'vehicle_id']),
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);

        $rows = $this->rows($report, $request)->get();
        $fileName = "job-card-{$report}-" . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $first = $rows->first();

            if ($first) {
                fputcsv($out, array_keys($first->toArray()));
                foreach ($rows as $row) {
                    // Guard against CSV injection in any user-entered text.
                    fputcsv($out, array_map([$this, 'sanitise'], array_values($row->toArray())));
                }
            }

            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    /** A leading =, +, - or @ makes spreadsheet software evaluate the cell as a formula. */
    private function sanitise($value)
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function rows(string $report, Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');

        if ($report === 'notes') {
            return FleetJobCardNote::query()
                ->join('fleet_job_cards', 'fleet_job_cards.id', '=', 'fleet_job_card_notes.job_card_id')
                ->when($from, fn ($q) => $q->whereDate('fleet_job_card_notes.created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('fleet_job_card_notes.created_at', '<=', $to))
                ->orderByDesc('fleet_job_card_notes.created_at')
                ->select([
                    'fleet_job_cards.card_number',
                    'fleet_job_card_notes.note_type',
                    'fleet_job_card_notes.body',
                    'fleet_job_card_notes.created_at',
                ]);
        }

        if ($report === 'parts') {
            return FleetJobCardPart::query()
                ->join('fleet_job_cards', 'fleet_job_cards.id', '=', 'fleet_job_card_parts.job_card_id')
                ->when($from, fn ($q) => $q->whereDate('fleet_job_cards.opened_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('fleet_job_cards.opened_date', '<=', $to))
                ->when($request->query('vehicle_id'), fn ($q, $v) => $q->where('fleet_job_cards.vehicle_id', $v))
                ->orderByDesc('fleet_job_card_parts.created_at')
                ->select([
                    'fleet_job_cards.card_number',
                    'fleet_job_card_parts.name',
                    'fleet_job_card_parts.part_number',
                    'fleet_job_card_parts.quantity',
                    'fleet_job_card_parts.unit_price',
                    'fleet_job_card_parts.line_total',
                ]);
        }

        return FleetJobCard::query()
            ->leftJoin('fleet_vehicles', 'fleet_vehicles.id', '=', 'fleet_job_cards.vehicle_id')
            ->when($from, fn ($q) => $q->whereDate('fleet_job_cards.opened_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('fleet_job_cards.opened_date', '<=', $to))
            ->when($request->query('status'), fn ($q, $s) => $q->where('fleet_job_cards.status', $s))
            ->when($request->query('vehicle_id'), fn ($q, $v) => $q->where('fleet_job_cards.vehicle_id', $v))
            ->orderByDesc('fleet_job_cards.opened_date')
            ->select([
                'fleet_job_cards.card_number',
                'fleet_vehicles.registration_number',
                'fleet_job_cards.status',
                'fleet_job_cards.priority',
                'fleet_job_cards.opened_date',
                'fleet_job_cards.completed_at',
                'fleet_job_cards.parts_cost',
                'fleet_job_cards.labour_cost',
                'fleet_job_cards.total_cost',
            ]);
    }
}
