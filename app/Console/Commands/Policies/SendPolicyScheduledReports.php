<?php

namespace App\Console\Commands\Policies;

use App\Mail\PolicyReportMail;
use App\Modules\Policies\Controllers\PolicyInsightsController;
use App\Modules\Policies\Models\PolicyScheduledReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class SendPolicyScheduledReports extends Command
{
    use RunsPerTenant;

    protected $signature   = 'policy:send-scheduled-reports';
    protected $description = 'Generate and email due policy scheduled reports';

    private array $reportTitles = [
        'violation'       => 'Violation Reports',
        'compliance'      => 'Compliance Reports',
        'exception'       => 'Exception Reports',
        'attestations'    => 'Attestation Reports',
        'policies'        => 'Policy Inventory Reports',
        'questionResults' => 'Question Results Reports',
        'policyEvents'    => 'Policy Events Reports',
        'investigations'  => 'Investigation Reports',
    ];

    public function handle(): int
    {
        $this->eachTenant(app(TenantContext::class), function () {
            $due = PolicyScheduledReport::where('active', true)
                ->where(fn($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
                ->get();

            if ($due->isEmpty()) {
                $this->info('No policy reports due.');
                return;
            }

            $controller = app(PolicyInsightsController::class);

            foreach ($due as $scheduled) {
                try {
                    $this->processReport($scheduled, $controller);
                    $this->info("Sent: {$scheduled->name}");
                } catch (\Throwable $e) {
                    $this->error("Failed [{$scheduled->name}]: {$e->getMessage()}");
                }
            }
        });

        return self::SUCCESS;
    }

    private function processReport(PolicyScheduledReport $scheduled, PolicyInsightsController $controller): void
    {
        $rows  = $this->fetchRows($scheduled->report_key, $controller);
        $title = $this->reportTitles[$scheduled->report_key] ?? $scheduled->report_key;
        $slug  = str_replace(' ', '-', strtolower($scheduled->report_key));
        $date  = now()->format('Y-m-d');

        $attachments = [];
        $tmpFiles    = [];

        if (in_array($scheduled->format, ['pdf', 'both'], true)) {
            $path = $this->buildPdf($title, $rows, $slug, $date);
            $attachments[] = ['path' => $path, 'name' => "policy-{$slug}-{$date}.pdf"];
            $tmpFiles[]    = $path;
        }

        if (in_array($scheduled->format, ['excel', 'both'], true)) {
            $path = $this->buildExcel($rows, $slug, $date);
            $attachments[] = ['path' => $path, 'name' => "policy-{$slug}-{$date}.xlsx"];
            $tmpFiles[]    = $path;
        }

        $recipients = array_map('trim', explode(',', $scheduled->recipients));
        $recipients = array_filter($recipients, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));

        foreach ($recipients as $email) {
            Mail::to($email)->send(new PolicyReportMail(
                reportName:  $scheduled->name,
                reportTitle: $title,
                cadence:     $scheduled->cadence,
                attachments: $attachments,
            ));
        }

        $scheduled->update([
            'last_run_at' => now(),
            'next_run_at' => $scheduled->computeNextRun(),
        ]);

        foreach ($tmpFiles as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    private function fetchRows(string $key, PolicyInsightsController $controller): array
    {
        return $controller->rowsByKey($key);
    }

    private function buildPdf(string $title, array $rows, string $slug, string $date): string
    {
        $headers = array_keys((array) ($rows[0] ?? []));

        $html = '<html><head><style>'
            . 'body{font-family:Arial,sans-serif;font-size:11px;}'
            . 'h2{margin:0 0 8px 0;color:#005f56;}'
            . 'p{margin:0 0 12px 0;color:#666;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{border:1px solid #ddd;padding:6px;text-align:left;}'
            . 'th{background:#e8f5f3;}'
            . '</style></head><body>'
            . '<h2>' . e($title) . '</h2>'
            . '<p>Generated ' . e($date) . '</p>'
            . '<table><thead><tr>';

        foreach ($headers as $h) {
            $html .= '<th>' . e((string) $h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $h) {
                $html .= '<td>' . e((string) ($row[$h] ?? '-')) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        $path = sys_get_temp_dir() . "/policy-{$slug}-{$date}.pdf";
        file_put_contents($path, Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output());
        return $path;
    }

    private function buildExcel(array $rows, string $slug, string $date): string
    {
        $path    = sys_get_temp_dir() . "/policy-{$slug}-{$date}.xlsx";
        $content = Excel::raw(
            new \App\Modules\Policies\Exports\PolicyReportRowsExport($rows),
            \Maatwebsite\Excel\Excel::XLSX,
        );
        file_put_contents($path, $content);
        return $path;
    }
}
