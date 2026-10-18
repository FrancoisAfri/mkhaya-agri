<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Modules\Policies\Exports\PolicyReportRowsExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetReportsController
{
    // ── Page ──────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        return Inertia::render('Modules/Assets/Reports/Index', [
            'register'    => $this->registerReport(),
            'assignments' => $this->assignmentsReport(),
            'maintenance' => $this->maintenanceReport(),
            'depreciation'=> $this->depreciationReport(),
            'incidents'   => $this->incidentsReport(),
            'movements'   => $this->movementsReport(),
            'clearances'  => $this->clearancesReport(),
            'categories'  => $this->categoryOptions(),
            'locations'   => $this->locationOptions(),
            'statuses'    => ['active', 'in_maintenance', 'disposed', 'missing', 'retired'],
        ]);
    }

    // ── Generate (JSON count) ─────────────────────────────────────────────────

    public function generate(Request $request, string $reportKey): JsonResponse
    {
        $rows = $this->rowsByKey($reportKey);
        $rows = $this->applyFilters($rows, $request);

        return response()->json([
            'report'       => $reportKey,
            'count'        => count($rows),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(Request $request, string $reportKey, string $format)
    {
        if (!in_array($format, ['xlsx', 'pdf', 'csv'], true)) {
            abort(404, 'Unsupported format');
        }

        $rows = $this->rowsByKey($reportKey);
        $rows = $this->applyFilters($rows, $request);

        if ($format === 'xlsx') {
            return $this->xlsxExport($reportKey, $rows);
        }

        if ($format === 'csv') {
            return $this->csvExport($reportKey, $rows);
        }

        return $this->pdfExport($reportKey, $rows);
    }

    // ── Report builders ───────────────────────────────────────────────────────

    private function registerReport(): array
    {
        if (!Schema::hasTable('assets')) {
            return [];
        }

        $query = DB::table('assets as a')
            ->whereNull('a.deleted_at')
            ->leftJoin('asset_categories as ac', 'ac.id', '=', 'a.asset_category_id')
            ->leftJoin('asset_type as at', 'at.id', '=', 'a.asset_type_id')
            ->leftJoin('asset_locations as al', 'al.id', '=', 'a.location_id')
            ->select(
                'a.uuid',
                'a.asset_number',
                'a.name',
                'a.serial_number',
                'a.asset_tag',
                'a.condition',
                'a.asset_status',
                'a.purchase_cost',
                'a.purchase_date',
                'a.warranty_expiry',
                'ac.name as category',
                'at.name as type',
                'al.name as location',
            )
            ->orderBy('a.asset_number')
            ->limit(500)
            ->get();

        return $query->map(fn ($r) => [
            'asset_number'    => $r->asset_number ?? '—',
            'name'            => $r->name,
            'serial_number'   => $r->serial_number ?? '—',
            'asset_tag'       => $r->asset_tag ?? '—',
            'category'        => $r->category ?? 'Uncategorised',
            'type'            => $r->type ?? '—',
            'location'        => $r->location ?? 'Unassigned',
            'condition'       => $r->condition ?? '—',
            'status'          => $r->asset_status ?? '—',
            'purchase_cost'   => $r->purchase_cost ? 'R ' . number_format((float) $r->purchase_cost, 2) : '—',
            'purchase_date'   => $this->fmt($r->purchase_date, false),
            'warranty_expiry' => $this->fmt($r->warranty_expiry, false),
        ])->toArray();
    }

    private function assignmentsReport(): array
    {
        if (!Schema::hasTable('asset_assignments')) {
            return [];
        }

        return DB::table('asset_assignments as aa')
            ->join('assets as a', 'a.id', '=', 'aa.asset_id')
            ->leftJoin('users as u', 'u.id', '=', 'aa.assigned_to')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'u.id')
            ->leftJoin('users as ab', 'ab.id', '=', 'aa.assigned_by')
            ->leftJoin('hr_people as hpb', 'hpb.user_id', '=', 'ab.id')
            ->leftJoin('asset_locations as al', 'al.id', '=', 'aa.location_id')
            ->select(
                'aa.uuid',
                'a.asset_number',
                'a.name as asset_name',
                DB::raw("TRIM(CONCAT(COALESCE(hp.first_name,''), ' ', COALESCE(hp.surname,''))) as assigned_to"),
                DB::raw("TRIM(CONCAT(COALESCE(hpb.first_name,''), ' ', COALESCE(hpb.surname,''))) as assigned_by"),
                'al.name as location',
                'aa.assignment_type',
                'aa.effective_date',
                'aa.return_due_date',
                'aa.returned_at',
                'aa.is_current',
            )
            ->orderByDesc('aa.effective_date')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'asset_number'   => $r->asset_number ?? '—',
                'asset'          => $r->asset_name,
                'assigned_to'    => $r->assigned_to ?? '—',
                'assigned_by'    => $r->assigned_by ?? '—',
                'location'       => $r->location ?? '—',
                'type'           => $r->assignment_type ?? '—',
                'effective_date' => $this->fmt($r->effective_date, false),
                'due_date'       => $this->fmt($r->return_due_date, false),
                'returned_at'    => $this->fmt($r->returned_at),
                'status'         => $r->returned_at ? 'returned' : ($r->is_current ? 'active' : 'inactive'),
            ])->toArray();
    }

    private function maintenanceReport(): array
    {
        if (!Schema::hasTable('asset_maintenance_schedules')) {
            return [];
        }

        return DB::table('asset_maintenance_schedules as ms')
            ->join('assets as a', 'a.id', '=', 'ms.asset_id')
            ->leftJoin('users as u', 'u.id', '=', 'ms.assigned_to')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'u.id')
            ->select(
                'ms.uuid',
                'a.asset_number',
                'a.name as asset_name',
                'ms.title',
                'ms.maintenance_type',
                'ms.status',
                'ms.due_date',
                'ms.started_at',
                'ms.completed_at',
                'ms.estimated_cost',
                'ms.actual_cost',
                DB::raw("TRIM(CONCAT(COALESCE(hp.first_name,''), ' ', COALESCE(hp.surname,''))) as assigned_to"),
            )
            ->orderByDesc('ms.due_date')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'asset_number'  => $r->asset_number ?? '—',
                'asset'         => $r->asset_name,
                'title'         => $r->title,
                'type'          => $r->maintenance_type ?? '—',
                'status'        => $r->status ?? '—',
                'due_date'      => $this->fmt($r->due_date, false),
                'started_at'    => $this->fmt($r->started_at),
                'completed_at'  => $this->fmt($r->completed_at),
                'assigned_to'   => $r->assigned_to ?? '—',
                'est_cost'      => $r->estimated_cost ? 'R ' . number_format((float) $r->estimated_cost, 2) : '—',
                'actual_cost'   => $r->actual_cost ? 'R ' . number_format((float) $r->actual_cost, 2) : '—',
            ])->toArray();
    }

    private function depreciationReport(): array
    {
        if (!Schema::hasTable('asset_depreciations')) {
            return [];
        }

        return DB::table('asset_depreciations as ad')
            ->join('assets as a', 'a.id', '=', 'ad.asset_id')
            ->leftJoin('asset_categories as ac', 'ac.id', '=', 'a.asset_category_id')
            ->select(
                'ad.uuid',
                'a.asset_number',
                'a.name as asset_name',
                'ac.name as category',
                'ad.months',
                'ad.years',
                'ad.initial_amount',
                'ad.amount_monthly',
                'ad.balance_amount',
                'ad.created_at',
            )
            ->orderByDesc('ad.created_at')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'asset_number'   => $r->asset_number ?? '—',
                'asset'          => $r->asset_name,
                'category'       => $r->category ?? 'Uncategorised',
                'useful_life'    => ($r->years ? $r->years . ' yr' : '') . ($r->months ? ' ' . $r->months . ' mo' : ''),
                'initial_value'  => $r->initial_amount ? 'R ' . number_format((float) $r->initial_amount, 2) : '—',
                'monthly_charge' => $r->amount_monthly ? 'R ' . number_format((float) $r->amount_monthly, 2) : '—',
                'book_value'     => $r->balance_amount ? 'R ' . number_format((float) $r->balance_amount, 2) : '—',
                'created_at'     => $this->fmt($r->created_at, false),
            ])->toArray();
    }

    private function incidentsReport(): array
    {
        if (!Schema::hasTable('asset_incidents')) {
            return [];
        }

        return DB::table('asset_incidents as ai')
            ->whereNull('ai.deleted_at')
            ->join('assets as a', 'a.id', '=', 'ai.asset_id')
            ->leftJoin('users as u', 'u.id', '=', 'ai.reported_by')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'u.id')
            ->select(
                'ai.uuid',
                'a.asset_number',
                'a.name as asset_name',
                DB::raw("TRIM(CONCAT(COALESCE(hp.first_name,''), ' ', COALESCE(hp.surname,''))) as reported_by"),
                'ai.title',
                'ai.severity',
                'ai.repair_cost',
                'ai.is_resolved',
                'ai.occurred_at',
                'ai.resolved_at',
                'ai.resolution_notes',
            )
            ->orderByDesc('ai.occurred_at')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'asset_number'     => $r->asset_number ?? '—',
                'asset'            => $r->asset_name,
                'title'            => $r->title,
                'reported_by'      => $r->reported_by ?? '—',
                'severity'         => $r->severity ?? '—',
                'status'           => $r->is_resolved ? 'resolved' : 'open',
                'repair_cost'      => $r->repair_cost ? 'R ' . number_format((float) $r->repair_cost, 2) : '—',
                'occurred_at'      => $this->fmt($r->occurred_at),
                'resolved_at'      => $this->fmt($r->resolved_at),
                'resolution_notes' => $r->resolution_notes ?? '—',
            ])->toArray();
    }

    private function movementsReport(): array
    {
        if (!Schema::hasTable('asset_movements')) {
            return [];
        }

        return DB::table('asset_movements as am')
            ->join('assets as a', 'a.id', '=', 'am.asset_id')
            ->leftJoin('asset_locations as fl', 'fl.id', '=', 'am.from_location_id')
            ->leftJoin('asset_locations as tl', 'tl.id', '=', 'am.to_location_id')
            ->leftJoin('users as u', 'u.id', '=', 'am.moved_by')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'u.id')
            ->select(
                'am.uuid',
                'a.asset_number',
                'a.name as asset_name',
                'fl.name as from_location',
                'tl.name as to_location',
                DB::raw("TRIM(CONCAT(COALESCE(hp.first_name,''), ' ', COALESCE(hp.surname,''))) as moved_by"),
                'am.reason',
                'am.moved_at',
            )
            ->orderByDesc('am.moved_at')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'asset_number'  => $r->asset_number ?? '—',
                'asset'         => $r->asset_name,
                'from_location' => $r->from_location ?? 'Unknown',
                'to_location'   => $r->to_location ?? 'Unknown',
                'moved_by'      => $r->moved_by ?? '—',
                'reason'        => $r->reason ?? '—',
                'moved_at'      => $this->fmt($r->moved_at),
            ])->toArray();
    }

    private function clearancesReport(): array
    {
        if (!Schema::hasTable('asset_exit_clearances')) {
            return [];
        }

        return DB::table('asset_exit_clearances as ec')
            ->whereNull('ec.deleted_at')
            ->leftJoin('users as u', 'u.id', '=', 'ec.user_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'u.id')
            ->leftJoin('users as ib', 'ib.id', '=', 'ec.initiated_by')
            ->leftJoin('hr_people as hpb', 'hpb.user_id', '=', 'ib.id')
            ->select(
                'ec.uuid',
                DB::raw("TRIM(CONCAT(COALESCE(hp.first_name,''), ' ', COALESCE(hp.surname,''))) as employee"),
                DB::raw("TRIM(CONCAT(COALESCE(hpb.first_name,''), ' ', COALESCE(hpb.surname,''))) as initiated_by"),
                'ec.status',
                'ec.target_exit_date',
                'ec.cleared_at',
                'ec.notes',
            )
            ->orderByDesc('ec.target_exit_date')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'employee'         => $r->employee ?? '—',
                'initiated_by'     => $r->initiated_by ?? '—',
                'status'           => $r->status ?? '—',
                'target_exit_date' => $this->fmt($r->target_exit_date, false),
                'cleared_at'       => $this->fmt($r->cleared_at),
                'notes'            => $r->notes ?? '—',
            ])->toArray();
    }

    // ── Filter helpers ────────────────────────────────────────────────────────

    private function applyFilters(array $rows, Request $request): array
    {
        $category  = $request->query('category');
        $location  = $request->query('location');
        $status    = strtolower((string) $request->query('status', ''));
        $fromTs    = $this->tsOrNull($request->query('dateFrom'));
        $toTs      = $this->tsOrNull($request->query('dateTo'));

        return array_values(array_filter($rows, function ($row) use ($category, $location, $status, $fromTs, $toTs) {
            if (!is_array($row)) return false;

            if (!empty($category) && isset($row['category']) && $row['category'] !== $category) return false;
            if (!empty($location) && isset($row['location']) && $row['location'] !== $location) return false;

            if ($status !== '') {
                $candidates = [
                    strtolower((string) ($row['status'] ?? '')),
                    strtolower((string) ($row['asset_status'] ?? '')),
                    strtolower((string) ($row['condition'] ?? '')),
                ];
                if (!in_array($status, $candidates, true)) return false;
            }

            if ($fromTs !== null || $toTs !== null) {
                $rowTs = $this->extractRowDate($row);
                if ($rowTs !== null) {
                    if ($fromTs !== null && $rowTs < $fromTs) return false;
                    if ($toTs   !== null && $rowTs > $toTs)   return false;
                }
            }

            return true;
        }));
    }

    private function extractRowDate(array $row): ?int
    {
        $keys = ['purchase_date', 'effective_date', 'due_date', 'occurred_at', 'moved_at', 'created_at', 'target_exit_date'];
        foreach ($keys as $key) {
            if (!isset($row[$key]) || $row[$key] === '—') continue;
            $ts = $this->tsOrNull($row[$key]);
            if ($ts !== null) return $ts;
        }
        return null;
    }

    private function tsOrNull($value): ?int
    {
        if (empty($value) && $value !== '0') return null;
        if (is_numeric($value)) return (int) $value;
        $ts = strtotime((string) $value);
        return $ts === false ? null : $ts;
    }

    private function fmt($value, bool $withTime = true): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            $c = is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
            return $withTime ? $c->format('Y-m-d H:i') : $c->format('Y-m-d');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    // ── Lookup options ────────────────────────────────────────────────────────

    private function categoryOptions(): array
    {
        if (!Schema::hasTable('asset_categories')) return [];
        return DB::table('asset_categories')->where('status', 1)->orderBy('name')->pluck('name')->toArray();
    }

    private function locationOptions(): array
    {
        if (!Schema::hasTable('asset_locations')) return [];
        return DB::table('asset_locations')->where('status', 1)->orderBy('name')->pluck('name')->toArray();
    }

    // ── Router ────────────────────────────────────────────────────────────────

    public function rowsByKey(string $key): array
    {
        return match ($key) {
            'register'    => $this->registerReport(),
            'assignments' => $this->assignmentsReport(),
            'maintenance' => $this->maintenanceReport(),
            'depreciation'=> $this->depreciationReport(),
            'incidents'   => $this->incidentsReport(),
            'movements'   => $this->movementsReport(),
            'clearances'  => $this->clearancesReport(),
            default       => [],
        };
    }

    public function reportKeyLabel(string $key): ?string
    {
        return [
            'register'    => 'Asset Register',
            'assignments' => 'Asset Assignments',
            'maintenance' => 'Maintenance Schedule',
            'depreciation'=> 'Depreciation Report',
            'incidents'   => 'Asset Incidents',
            'movements'   => 'Asset Movements',
            'clearances'  => 'Exit Clearances',
        ][$key] ?? null;
    }

    // ── Export helpers ────────────────────────────────────────────────────────

    private function xlsxExport(string $key, array $rows)
    {
        $filename = sprintf('asset_%s_%s.xlsx', $key, now()->format('Y-m-d'));
        return Excel::download(new PolicyReportRowsExport($rows), $filename);
    }

    private function csvExport(string $key, array $rows): StreamedResponse
    {
        $filename = sprintf('asset_%s_%s.csv', $key, now()->format('Y-m-d'));
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) return;
            if (count($rows) > 0) {
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $row) fputcsv($out, array_map([self::class, 'csvSafe'], array_values($row)));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Neutralise CSV formula injection: prefix risky leading chars with a quote. */
    private static function csvSafe($value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function pdfExport(string $key, array $rows)
    {
        $filename = sprintf('asset_%s_%s.pdf', $key, now()->format('Y-m-d'));
        $headers  = count($rows) > 0 ? array_keys($rows[0]) : [];

        $reportTitles = [
            'register'    => 'Asset Register',
            'assignments' => 'Asset Assignments',
            'maintenance' => 'Maintenance Schedule',
            'depreciation'=> 'Depreciation Report',
            'incidents'   => 'Asset Incidents',
            'movements'   => 'Asset Movements',
            'clearances'  => 'Exit Clearances',
        ];

        $html = '<html><head><style>'
            . 'body{font-family:Arial,sans-serif;font-size:10px;}'
            . 'h2{margin:0 0 4px 0;}p{margin:0 0 10px;color:#666;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{border:1px solid #ddd;padding:5px;text-align:left;}'
            . 'th{background:#f3f4f6;font-weight:600;}'
            . '</style></head><body>'
            . '<h2>' . e($reportTitles[$key] ?? $key) . '</h2>'
            . '<p>Generated ' . e(now()->format('Y-m-d H:i')) . ' · ' . count($rows) . ' rows</p>'
            . '<table><thead><tr>';

        foreach ($headers as $h) $html .= '<th>' . e(ucwords(str_replace('_', ' ', $h))) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $h) $html .= '<td>' . e((string) ($row[$h] ?? '—')) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($filename);
    }
}
