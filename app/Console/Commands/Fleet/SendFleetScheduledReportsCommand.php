<?php

namespace App\Console\Commands\Fleet;

use App\Mail\PolicyReportMail;
use App\Modules\Fleet\Controllers\FleetReportController;
use App\Modules\Fleet\Models\FleetScheduledReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendFleetScheduledReportsCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'fleet:send-scheduled-reports';
    protected $description = 'Generate and email due Fleet scheduled reports';

    public function handle(): int
    {
        $this->eachTenant(app(TenantContext::class), function () {
            $due = FleetScheduledReport::where('active', true)
                ->where(fn($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
                ->get();

            if ($due->isEmpty()) {
                $this->info('No fleet reports due.');
                return;
            }

            $controller = app(FleetReportController::class);

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

    private function processReport(FleetScheduledReport $scheduled, FleetReportController $controller): void
    {
        $rows  = $controller->rowsByKey($scheduled->report_key);
        $title = "Fleet — {$scheduled->report_key}";
        $slug  = 'fleet-' . str_replace('_', '-', $scheduled->report_key);
        $date  = now()->format('Y-m-d');

        $attachments = [];
        $tmpFiles    = [];

        if (in_array($scheduled->format, ['pdf', 'both'], true)) {
            $path = $this->buildPdf($title, $rows, $slug, $date);
            $attachments[] = ['path' => $path, 'name' => "{$slug}-{$date}.pdf"];
            $tmpFiles[]    = $path;
        }

        if (in_array($scheduled->format, ['excel', 'both'], true)) {
            $path = $this->buildCsv($rows, $slug, $date);
            $attachments[] = ['path' => $path, 'name' => "{$slug}-{$date}.csv"];
            $tmpFiles[]    = $path;
        }

        $recipients = array_filter(
            array_map('trim', explode(',', $scheduled->recipients)),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        );

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
            if (file_exists($f)) unlink($f);
        }
    }

    private function buildPdf(string $title, array $rows, string $slug, string $date): string
    {
        $headers = empty($rows) ? [] : array_keys((array) $rows[0]);

        $html = '<html><head><style>'
            . 'body{font-family:Arial,sans-serif;font-size:10px;}'
            . 'h2{margin:0 0 4px 0;color:#1565C0;}'
            . 'p{margin:0 0 10px 0;color:#666;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{border:1px solid #ddd;padding:5px;text-align:left;}'
            . 'th{background:#E3F2FD;font-weight:bold;}'
            . '</style></head><body>'
            . '<h2>' . e($title) . '</h2>'
            . '<p>Generated ' . e($date) . '</p>'
            . '<table><thead><tr>';

        foreach ($headers as $h) {
            $html .= '<th>' . e((string) $h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $row = (array) $row;
            $html .= '<tr>';
            foreach ($headers as $h) {
                $html .= '<td>' . e((string) ($row[$h] ?? '-')) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';

        $path = sys_get_temp_dir() . "/{$slug}-{$date}.pdf";
        file_put_contents($path, Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output());
        return $path;
    }

    private function buildCsv(array $rows, string $slug, string $date): string
    {
        $path = sys_get_temp_dir() . "/{$slug}-{$date}.csv";
        $fp   = fopen($path, 'w');

        if (!empty($rows)) {
            fputcsv($fp, array_keys((array) $rows[0]));
            foreach ($rows as $row) {
                fputcsv($fp, array_values((array) $row));
            }
        }

        fclose($fp);
        return $path;
    }
}
