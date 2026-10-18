<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Warehouse reports and their exports (WMS-185).
 *
 * Each report names the permission it needs, whether it is sensitive, and its columns; value columns
 * appear only for people with the accounting grant, and a report with values is sensitive. The same
 * permission is checked when the report is asked for, when it is built in the background and when
 * its file is downloaded. Every run keeps its filters and generation time and prints them on the
 * file; one larger than SYNC_ROWS is built in the background.
 */
class WarehouseReports
{
    use ScopesRawQueries;

    public const SYNC_ROWS = 2000;

    public const MAX_ROWS = 200000;

    public const FILTERS = ['from', 'to', 'warehouse_id', 'item', 'supplier_id', 'status'];

    /** key => [title, permission, always sensitive, filters it uses] */
    public const REPORTS = [
        'stock_on_hand' => ['Stock on hand', RoleCatalog::VIEW, false, ['warehouse_id', 'item', 'status']],
        'movements' => ['Stock movements', RoleCatalog::VIEW, false, ['from', 'to', 'warehouse_id', 'item']],
        'ageing' => ['Stock ageing', RoleCatalog::VIEW, false, ['warehouse_id', 'item']],
        'expiry' => ['Expiry exposure', RoleCatalog::VIEW, false, ['warehouse_id']],
        'reservations' => ['Open reservations', RoleCatalog::VIEW, false, ['warehouse_id', 'item']],
        'adjustments' => ['Stock adjustments', RoleCatalog::VIEW, false, ['from', 'to', 'warehouse_id', 'item', 'status']],
        'supplier_performance' => ['Supplier performance', RoleCatalog::VIEW, false, ['from', 'to', 'supplier_id']],
        'overdue_purchase_orders' => ['Overdue purchase orders', RoleCatalog::VIEW, false, ['supplier_id']],
        'losses' => ['Loss findings', RoleCatalog::INVESTIGATE, true, ['from', 'to', 'warehouse_id', 'status']],
        'excess' => ['Excess and obsolete stock', RoleCatalog::PLAN, false, []],
    ];

    /** @var list<int>|null warehouses the requester may see; set for the run being built */
    private ?array $allowed = null;

    private const COST = "CASE WHEN COALESCE(i.is_valued, true) THEN (CASE i.cost_method WHEN 'standard' THEN i.standard_cost ELSE i.avg_cost END) ELSE 0 END";

    /** The reports this person may run, with what each will contain for them. */
    public function catalogue(User $user): array
    {
        return collect(self::REPORTS)->filter(fn ($r) => $user->can($r[1]))
            ->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'filters' => $r[3], 'sensitive' => $r[2] || $this->withValues($key, $user)])->values()->all();
    }

    public function withValues(string $report, User $user): bool
    {
        return in_array($report, ['stock_on_hand', 'ageing', 'expiry', 'adjustments', 'excess', 'losses'], true) && $user->can(RoleCatalog::ACCOUNTING);
    }

    public function assertMay(string $report, User $user): void
    {
        if (! isset(self::REPORTS[$report])) {
            throw new RuntimeException("Unknown report {$report}.");
        }
        abort_unless($user->can(self::REPORTS[$report][1]), 403, 'You may not run this report.');
    }

    /** Record the request; run it now if small, or leave it QUEUED for the job. */
    public function request(string $report, array $filters, string $format, User $user): object
    {
        $this->assertMay($report, $user);
        $params = array_filter(array_intersect_key($filters, array_flip(self::REPORTS[$report][3])), fn ($v) => $v !== null && $v !== '');
        $values = $this->withValues($report, $user);
        $id = DB::table('wms_report_runs')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->tenantId(), 'report' => $report,
            'title' => self::REPORTS[$report][0], 'parameters' => json_encode($params), 'format' => strtoupper($format), 'with_values' => $values,
            'sensitive' => self::REPORTS[$report][2] || $values, 'status' => 'QUEUED', 'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('wms_report_runs')->where('id', $id)->first();
    }

    public function isLarge(object $run): bool
    {
        return $this->estimate($run->report, json_decode($run->parameters, true)) > self::SYNC_ROWS;
    }

    /** Build the file and keep it. The requester's permission is checked again: it may have changed. */
    public function generate(object $run): object
    {
        $user = User::query()->withoutGlobalScopes()->findOrFail($run->requested_by);
        DB::table('wms_report_runs')->where('id', $run->id)->update(['status' => 'RUNNING', 'updated_at' => now()]);
        try {
            $this->assertMay($run->report, $user);
            $params = json_decode($run->parameters, true);
            if (! empty($params['warehouse_id']) && ! app(WarehouseAccess::class)->mayUse($user, (int) $params['warehouse_id'])) {
                abort(403, 'You may not report on that warehouse.');
            }
            $this->allowed = app(WarehouseAccess::class)->visibleIds($user, (int) $run->company_id);
            [$headers, $rows] = $this->rows($run->report, $params, (bool) $run->with_values);
            $generated = now();
            $path = "warehouse-reports/{$run->company_id}/{$run->uuid}.".strtolower($run->format);
            $label = $this->labelParameters($params);
            $bytes = match ($run->format) {
                'CSV' => $this->csv($run, $headers, $rows, $label, $generated),
                'XLSX' => $this->xlsx($run, $headers, $rows, $label, $generated),
                'PDF' => Pdf::loadView('pdf.warehouse.report', ['title' => $run->title, 'headers' => $headers, 'rows' => $rows, 'parameters' => $label,
                    'company' => DB::table('companies')->where('id', $run->company_id)->value('name'), 'by' => $user->email,
                    'generated' => $generated->toDayDateTimeString(), 'sensitive' => (bool) $run->sensitive])->setPaper('a4', count($headers) > 7 ? 'landscape' : 'portrait')->output(),
            };
            Storage::disk('local')->put($path, $bytes);
            DB::table('wms_report_runs')->where('id', $run->id)->update(['status' => 'COMPLETE', 'rows' => count($rows), 'file_path' => $path,
                'generated_at' => $generated, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('wms_report_runs')->where('id', $run->id)->update(['status' => 'FAILED', 'failure_reason' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
            throw $e;
        }

        return DB::table('wms_report_runs')->where('id', $run->id)->first();
    }

    /** @return array{0: list<string>, 1: list<array>} */
    public function rows(string $report, array $p, bool $values): array
    {
        $from = Carbon::parse($p['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($p['to'] ?? now()->toDateString())->endOfDay();
        $v = fn (array $h, array $money) => $values ? array_merge($h, $money) : $h;

        return match ($report) {
            'stock_on_hand' => [$v(['Item', 'Name', 'Warehouse', 'Location', 'Lot / serial', 'Expires', 'Status', 'Qty', 'Unit'], ['Unit cost', 'Value']),
                $this->positions($p)->orderBy('i.code')->orderBy('w.code')->limit(self::MAX_ROWS)
                    ->get(['i.code', 'i.name', 'w.code as wh', 'b.code as bin', 'l.lot_number', 'l.expires_on', 'p.status', 'p.qty', 'i.uom', DB::raw(self::COST.' AS cost')])
                    ->map(fn ($r) => array_merge([$r->code, $r->name, $r->wh, $r->bin, $r->lot_number, $r->expires_on, $r->status, (float) $r->qty, $r->uom],
                        $values ? [round((float) $r->cost, 4), round((float) $r->qty * (float) $r->cost, 2)] : []))->all()],
            'movements' => [['When', 'Type', 'Item', 'Warehouse', 'From', 'To', 'Lot', 'Qty', 'Reason', 'Reference'],
                $this->movementQuery($p, $from, $to)->orderBy('m.id')->limit(self::MAX_ROWS)
                    ->get(['m.created_at', 'm.type', 'i.code', 'w.code as wh', 'fb.code as from_bin', 'tb.code as to_bin', 'l.lot_number', 'm.qty', 'm.reason_code', 'm.reference'])
                    ->map(fn ($r) => [Carbon::parse($r->created_at)->toDateTimeString(), $r->type, $r->code, $r->wh, $r->from_bin, $r->to_bin, $r->lot_number, (float) $r->qty, $r->reason_code, $r->reference])->all()],
            'ageing' => [$v(['Item', 'Warehouse', 'Qty', 'Last received', 'Days held'], ['Value']), $this->ageing($p, $values)],
            'expiry' => [$v(['Item', 'Lot', 'Warehouse', 'Expires', 'Days left', 'Qty', 'Unlikely to be used'], ['Exposure']),
                collect(app(ExpiryWatch::class)->atRisk($p['warehouse_id'] ?? null, 3650))->filter(fn ($e) => $this->allowed === null || in_array((int) $e['warehouse_id'], $this->allowed, true))->map(fn ($e) => array_merge([$e['item'], $e['lot'], $e['warehouse'], $e['expires_on'],
                    $e['days_left'], $e['qty'], $e['at_risk_qty']], $values ? [$e['exposure']] : []))->all()],
            'reservations' => [['Item', 'Warehouse', 'Reserved', 'Consumed', 'For', 'Since'],
                DB::table('fin_stock_reservations as r')->join('fin_stock_items as i', 'i.id', '=', 'r.item_id')->join('fin_warehouses as w', 'w.id', '=', 'r.warehouse_id')
                    ->where('w.is_wms_managed', true)->where('r.status', 'OPEN')->when($this->tenantId(), fn ($q, $c) => $q->where('r.company_id', $c))
                    ->when($this->allowed !== null, fn ($q) => $q->whereIn('r.warehouse_id', $this->allowed))
                    ->when($p['warehouse_id'] ?? null, fn ($q, $x) => $q->where('r.warehouse_id', $x))->when($p['item'] ?? null, fn ($q, $x) => $q->where('i.code', $x))
                    ->orderBy('r.id')->limit(self::MAX_ROWS)->get(['i.code', 'w.code as wh', 'r.qty', 'r.qty_consumed', 'r.source_type', 'r.created_at'])
                    ->map(fn ($r) => [$r->code, $r->wh, (float) $r->qty, (float) $r->qty_consumed, $r->source_type, Carbon::parse($r->created_at)->toDateString()])->all()],
            'adjustments' => [$v(['Number', 'When', 'Item', 'Warehouse', 'Qty', 'Status', 'Requested by', 'Decided by'], ['Value']),
                $this->adjustments($p, $from, $to)->orderBy('a.id')->limit(self::MAX_ROWS)
                    ->get(['a.number', 'a.occurred_at', 'i.code', 'w.code as wh', 'a.qty_delta', 'a.status', 'ru.email as requester', 'du.email as decider', 'a.value_estimate'])
                    ->map(fn ($r) => array_merge([$r->number, Carbon::parse($r->occurred_at)->toDateString(), $r->code, $r->wh, (float) $r->qty_delta, $r->status, $r->requester, $r->decider],
                        $values ? [round((float) $r->value_estimate, 2)] : []))->all()],
            'supplier_performance' => [['Supplier', 'Deliveries', 'On time %', 'Quantity accuracy %', 'Damage %', 'Rejection %', 'Lead time (days)', 'Returns'],
                collect(app(SupplierScorecard::class)->scores($from, $to, isset($p['supplier_id']) ? (int) $p['supplier_id'] : null))
                    ->map(fn ($s) => [$s['supplier'], $s['deliveries'], $this->pct($s['on_time_rate']), $this->pct($s['quantity_accuracy']), $this->pct($s['damage_rate']),
                        $this->pct($s['rejection_rate']), $s['avg_lead_time_days'], $s['returns']])->all()],
            'overdue_purchase_orders' => [['Purchase order', 'Supplier', 'Expected', 'Days late', 'Lines open'],
                collect(app(WarehouseDashboards::class)->suppliers(now()->subYear(), now(), isset($p['supplier_id']) ? (int) $p['supplier_id'] : null, false)['overdue'])
                    ->map(fn ($o) => [$o['po'], $o['supplier'], $o['expected'], $o['days_late'], $o['lines']])->all()],
            'losses' => [$v(['Number', 'Detected', 'Category', 'Priority', 'Status', 'Subject', 'Due'], ['Estimated', 'Verified']),
                DB::table('mfg_loss_findings')->whereNotNull('warehouse_id')->when($this->tenantId(), fn ($q, $c) => $q->where('company_id', $c))
                    ->whereBetween('detected_at', [$from, $to])->when($p['warehouse_id'] ?? null, fn ($q, $x) => $q->where('warehouse_id', $x))
                    ->when($p['status'] ?? null, fn ($q, $x) => $q->where('status', $x))->orderBy('id')->limit(self::MAX_ROWS)->get()
                    ->map(fn ($f) => array_merge([$f->number, Carbon::parse($f->detected_at)->toDateString(), $f->category, $f->priority, $f->status, $f->subject_label, $f->due_date],
                        $values ? [round((float) $f->estimated_impact, 2), $f->verified_loss !== null ? round((float) $f->verified_loss, 2) : null] : []))->all()],
            'excess' => [$v(['Item', 'Name', 'Warehouse', 'Class', 'Qty', 'Days idle', 'Months of cover', 'Excess qty'], ['Excess value']),
                collect(app(ExcessObsolete::class)->analyse()['rows'])->map(fn ($r) => array_merge([$r['item'], $r['name'], $r['warehouse'], $r['class'], $r['qty'],
                    $r['days_idle'], $r['months_cover'], $r['excess_qty']], $values ? [$r['excess_value']] : []))->all()],
        };
    }

    /** A cheap row count, to decide whether to build in the background. */
    public function estimate(string $report, array $p): int
    {
        $from = Carbon::parse($p['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($p['to'] ?? now()->toDateString())->endOfDay();

        return match ($report) {
            'stock_on_hand' => $this->positions($p)->count(),
            'movements' => $this->movementQuery($p, $from, $to)->count(),
            'adjustments' => $this->adjustments($p, $from, $to)->count(),
            default => 0,
        };
    }

    public function labelParameters(array $p): array
    {
        $out = [];
        foreach ($p as $k => $v) {
            $out[match ($k) { 'warehouse_id' => 'Warehouse', 'supplier_id' => 'Supplier', default => ucfirst($k) }] = match ($k) {
                'warehouse_id' => DB::table('fin_warehouses')->where('id', $v)->value('code') ?? $v,
                'supplier_id' => DB::table('suppliers')->where('id', $v)->value('company_name') ?? $v,
                default => $v,
            };
        }

        return $out ?: ['Filters' => 'none'];
    }

    private function csv(object $run, array $headers, array $rows, array $params, Carbon $generated): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, ["{$run->title} — generated {$generated->toIso8601String()}"]);
        fputcsv($h, [collect($params)->map(fn ($v, $k) => "{$k}: {$v}")->implode('; ')]);
        fputcsv($h, []);
        fputcsv($h, $headers);
        foreach ($rows as $r) {
            // A cell starting with = + - @ is a formula to a spreadsheet; neutralise it.
            fputcsv($h, array_map(fn ($c) => is_string($c) && $c !== '' && in_array($c[0], ['=', '+', '-', '@'], true) ? "'".$c : $c, array_values($r)));
        }
        rewind($h);

        return stream_get_contents($h);
    }

    private function xlsx(object $run, array $headers, array $rows, array $params, Carbon $generated): string
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getProperties()->setTitle($run->title)->setSubject(collect($params)->map(fn ($v, $k) => "{$k}: {$v}")->implode('; '))
            ->setCreated($generated->timestamp)->setKeywords($run->sensitive ? 'confidential' : '');
        $sheet = $book->getActiveSheet()->setTitle('Report');
        $clean = fn ($c) => is_string($c) && $c !== '' && in_array($c[0], ['=', '+', '-', '@'], true) ? "'".$c : $c;
        $sheet->fromArray([[$run->title], ['Generated '.$generated->toIso8601String()], [collect($params)->map(fn ($v, $k) => "{$k}: {$v}")->implode('; ')], [], $headers], null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A5:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($headers))).'5')->getFont()->setBold(true);
        if ($rows !== []) {
            $sheet->fromArray(array_map(fn ($r) => array_map($clean, array_values($r)), $rows), null, 'A6', true);
        }
        $sheet->freezePane('A6');
        $h = fopen('php://temp', 'r+');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($h);
        rewind($h);

        return stream_get_contents($h);
    }

    private function pct(?float $r): ?float
    {
        return $r === null ? null : round($r * 100, 1);
    }

    private function positions(array $p): Builder
    {
        return DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')->join('fin_warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->where('w.is_wms_managed', true)->where('p.qty', '>', 0)->when($this->tenantId(), fn ($q, $c) => $q->where('p.company_id', $c))
            ->when($p['warehouse_id'] ?? null, fn ($q, $x) => $q->where('p.warehouse_id', $x))->when($p['item'] ?? null, fn ($q, $x) => $q->where('i.code', $x))
            ->when($p['status'] ?? null, fn ($q, $x) => $q->where('p.status', $x))
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('p.warehouse_id', $this->allowed));
    }

    private function movementQuery(array $p, Carbon $from, Carbon $to): Builder
    {
        return DB::table('fin_stock_movements as m')->join('fin_stock_items as i', 'i.id', '=', 'm.item_id')->join('fin_warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('fin_stock_bins as fb', 'fb.id', '=', 'm.bin_id')->leftJoin('fin_stock_bins as tb', 'tb.id', '=', 'm.to_bin_id')->leftJoin('fin_stock_lots as l', 'l.id', '=', 'm.lot_id')
            ->where('w.is_wms_managed', true)->when($this->tenantId(), fn ($q, $c) => $q->where('m.company_id', $c))->whereBetween('m.created_at', [$from, $to])
            ->when($p['warehouse_id'] ?? null, fn ($q, $x) => $q->where('m.warehouse_id', $x))->when($p['item'] ?? null, fn ($q, $x) => $q->where('i.code', $x))
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('m.warehouse_id', $this->allowed));
    }

    private function adjustments(array $p, Carbon $from, Carbon $to): Builder
    {
        return DB::table('wms_stock_adjustments as a')->join('fin_stock_items as i', 'i.id', '=', 'a.item_id')->join('fin_warehouses as w', 'w.id', '=', 'a.warehouse_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'a.requested_by')->leftJoin('users as du', 'du.id', '=', 'a.decided_by')
            ->when($this->tenantId(), fn ($q, $c) => $q->where('a.company_id', $c))->whereBetween('a.occurred_at', [$from, $to])
            ->when($p['warehouse_id'] ?? null, fn ($q, $x) => $q->where('a.warehouse_id', $x))->when($p['item'] ?? null, fn ($q, $x) => $q->where('i.code', $x))
            ->when($p['status'] ?? null, fn ($q, $x) => $q->where('a.status', $x))
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('a.warehouse_id', $this->allowed));
    }

    private function ageing(array $p, bool $values): array
    {
        $cid = $this->tenantId();

        return DB::table('fin_stock_balances as b')->join('fin_stock_items as i', 'i.id', '=', 'b.item_id')->join('fin_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('w.is_wms_managed', true)->where('b.qty', '>', 0)->when($cid, fn ($q, $c) => $q->where('b.company_id', $c))
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('b.warehouse_id', $this->allowed))
            ->when($p['warehouse_id'] ?? null, fn ($q, $x) => $q->where('b.warehouse_id', $x))->when($p['item'] ?? null, fn ($q, $x) => $q->where('i.code', $x))
            ->leftJoinSub(DB::table('fin_stock_movements')->whereIn('type', WarehouseEvents::RECEIVING)->where('qty', '>', 0)->when($cid, fn ($q, $c) => $q->where('company_id', $c))
                ->groupBy('item_id', 'warehouse_id')->selectRaw('item_id, warehouse_id, MAX(created_at) AS at'), 'li', fn ($j) => $j->on('li.item_id', '=', 'b.item_id')->on('li.warehouse_id', '=', 'b.warehouse_id'))
            ->orderBy('li.at')->limit(self::MAX_ROWS)->get(['i.code', 'w.code as wh', 'b.qty', 'li.at', DB::raw(self::COST.' AS cost')])
            ->map(fn ($r) => array_merge([$r->code, $r->wh, (float) $r->qty, $r->at ? Carbon::parse($r->at)->toDateString() : null,
                $r->at ? (int) Carbon::parse($r->at)->diffInDays(now()) : null], $values ? [round((float) $r->qty * (float) $r->cost, 2)] : []))->all();
    }
}
