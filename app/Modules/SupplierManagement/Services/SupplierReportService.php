<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierBbbee;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierScar;
use App\Modules\SupplierManagement\Models\SupplierScreening;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Produces tabular Supplier Management report data ([headers, rows]).
 *
 * Also consumed by the scheduled-report command
 * (App\Console\Commands\Procurement\SendProcurementScheduledReportsCommand).
 */
class SupplierReportService
{
    /** Supplier status (is_active) integer => human label. */
    private const STATUS_LABELS = [
        0 => 'Pending',
        1 => 'Active',
        2 => 'Refused',
        3 => 'Pending KYC',
    ];

    /** @return array<string,string> */
    public function reportKeys(): array
    {
        return [
            'all'                => 'All Suppliers',
            'by_status'          => 'By Status',
            'by_segment'         => 'By Segment',
            'expiring_documents' => 'Expiring Documents',
            'risk_flags'         => 'Open Risk Flags',
            'screenings'         => 'Compliance Screenings',
            'scars'              => 'Open SCARs',
            'bbbee'              => 'B-BBEE Summary',
            'spend'              => 'Spend by Supplier',
        ];
    }

    /**
     * Build a single report.
     *
     * @return array{0: array<string>, 1: array<array<scalar>>} [$headers, $rows]
     */
    public function report(string $key): array
    {
        return match ($key) {
            'by_status'          => $this->byStatus(),
            'by_segment'         => $this->bySegment(),
            'expiring_documents' => $this->expiringDocuments(),
            'risk_flags'         => $this->riskFlags(),
            'screenings'         => $this->screenings(),
            'scars'              => $this->scars(),
            'bbbee'              => $this->bbbee(),
            'spend'              => $this->spend(),
            default              => $this->all(),
        };
    }

    private function statusLabel(?int $status): string
    {
        return self::STATUS_LABELS[(int) $status] ?? 'Unknown';
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function all(): array
    {
        $headers = ['Company', 'Reg No', 'Email', 'Status', 'Segment'];

        $rows = Supplier::query()
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'company_registration_number', 'email', 'is_active', 'segment'])
            ->map(fn (Supplier $s) => [
                (string) ($s->company_name ?? '—'),
                (string) ($s->company_registration_number ?? '—'),
                (string) ($s->email ?? '—'),
                $this->statusLabel($s->is_active),
                (string) ($s->segment ?? '—'),
            ])
            ->all();

        return [$headers, $rows];
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function byStatus(): array
    {
        $headers = ['Status', 'Suppliers'];

        $counts = Supplier::query()
            ->select('is_active', DB::raw('COUNT(*) as total'))
            ->groupBy('is_active')
            ->pluck('total', 'is_active');

        $rows = [];
        foreach (self::STATUS_LABELS as $value => $label) {
            $rows[] = [$label, (int) ($counts[$value] ?? 0)];
        }

        return [$headers, $rows];
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function bySegment(): array
    {
        $headers = ['Segment', 'Suppliers'];

        $rows = Supplier::query()
            ->select(DB::raw("COALESCE(NULLIF(segment, ''), 'Unsegmented') as seg"), DB::raw('COUNT(*) as total'))
            ->groupBy('seg')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [(string) $r->seg, (int) $r->total])
            ->all();

        return [$headers, $rows];
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function expiringDocuments(): array
    {
        $headers = ['Company', 'Document Type', 'Expiry Date', 'Days Left'];

        $today    = Carbon::today();
        $deadline = $today->copy()->addDays(90);

        $rows = SupplierDocument::query()
            ->with(['supplier:id,company_name', 'documentType:id,document_type_name'])
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$today, $deadline])
            ->orderBy('expiry_date')
            ->get()
            ->map(function (SupplierDocument $d) use ($today) {
                $expiry = $d->expiry_date;

                return [
                    (string) ($d->supplier?->company_name ?? '—'),
                    (string) ($d->documentType?->document_type_name ?? '—'),
                    $expiry ? $expiry->toDateString() : '—',
                    $expiry ? (int) $today->diffInDays($expiry, false) : 0,
                ];
            })
            ->all();

        return [$headers, $rows];
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function riskFlags(): array
    {
        $headers = ['Company', 'Type', 'Severity', 'Message', 'Raised'];

        $rows = SupplierRiskFlag::query()
            ->open()
            ->with('supplier:id,company_name')
            ->latest()
            ->get()
            ->map(fn (SupplierRiskFlag $f) => [
                (string) ($f->supplier?->company_name ?? '—'),
                (string) ($f->type ?? '—'),
                (string) ($f->severity ?? '—'),
                (string) ($f->message ?? '—'),
                optional($f->created_at)->toDateString() ?? '—',
            ])
            ->all();

        return [$headers, $rows];
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function screenings(): array
    {
        $headers = ['Company', 'Risk Level', 'Status', 'Screened At'];

        $rows = SupplierScreening::query()
            ->with('supplier:id,company_name')
            ->latest('screened_at')
            ->get()
            ->map(fn (SupplierScreening $s) => [
                (string) ($s->supplier?->company_name ?? '—'),
                (string) ($s->risk_level ?? '—'),
                (string) ($s->status ?? '—'),
                optional($s->screened_at)->toDateString() ?? '—',
            ])
            ->all();

        return [$headers, $rows];
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function scars(): array
    {
        $headers = ['SCAR No', 'Company', 'Title', 'Severity', 'Status', 'Due Date'];

        $rows = SupplierScar::query()
            ->open()
            ->with('supplier:id,company_name')
            ->orderBy('due_date')
            ->get()
            ->map(fn (SupplierScar $s) => [
                (string) ($s->scar_number ?? '—'),
                (string) ($s->supplier?->company_name ?? '—'),
                (string) ($s->title ?? '—'),
                (string) ($s->severity ?? '—'),
                (string) ($s->status ?? '—'),
                optional($s->due_date)->toDateString() ?? '—',
            ])
            ->all();

        return [$headers, $rows];
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function bbbee(): array
    {
        $headers = ['Company', 'Level', 'Recognition %', 'Certificate Expiry'];

        $rows = SupplierBbbee::query()
            ->with('supplier:id,company_name')
            ->orderBy('level')
            ->get()
            ->map(fn (SupplierBbbee $b) => [
                (string) ($b->supplier?->company_name ?? '—'),
                $b->level !== null ? (string) $b->level : '—',
                (string) ($b->recognition_pct ?? '0'),
                optional($b->certificate_expiry)->toDateString() ?? '—',
            ])
            ->all();

        return [$headers, $rows];
    }

    /** @return array{0: array<string>, 1: array<array<scalar>>} */
    private function spend(): array
    {
        $headers = ['Company', 'Total Spend', 'Invoices'];

        if (! Schema::hasTable('supplier_invoices')) {
            return [$headers, []];
        }

        $rows = DB::table('supplier_invoices')
            ->select('supplier_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as invoices'))
            ->whereNull('deleted_at')
            ->groupBy('supplier_id')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                $supplier = Supplier::find($row->supplier_id, ['id', 'company_name']);

                return [
                    (string) ($supplier?->company_name ?? '—'),
                    number_format((float) $row->total, 2, '.', ''),
                    (int) $row->invoices,
                ];
            })
            ->all();

        return [$headers, $rows];
    }
}
