<?php

namespace App\Modules\CRM\Jobs;

use App\Modules\CRM\Services\CrmReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a CRM report off the request thread and emails it to recipients
 * (spec section 8 — background job processing for heavy work). Used by both the
 * "run now" action and the scheduled-report runner.
 */
class GenerateCrmReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    /**
     * @param  array<int,string>  $recipients
     */
    /**
     * @param  array<int,string>  $recipients
     * @param  array<int,int>|null  $ownerIds  scope the report to these owners (null = all)
     */
    public function __construct(
        private readonly string $reportKey,
        private readonly array $recipients = [],
        private readonly ?array $ownerIds = null,
    ) {
    }

    public function handle(): void
    {
        [$headers, $rows] = (new CrmReportService())->forOwners($this->ownerIds)->dataset($this->reportKey);

        $csv = $this->toCsv($headers, $rows);
        $filename = "crm-{$this->reportKey}-" . now()->format('Y-m-d-His') . '.csv';
        $path = "reports/crm/{$filename}";
        Storage::disk('local')->put($path, $csv);

        foreach ($this->recipients as $email) {
            try {
                Mail::raw(
                    "Your CRM \"{$this->reportKey}\" report is attached.",
                    function ($msg) use ($email, $csv, $filename) {
                        $msg->to($email)
                            ->subject("CRM Report: {$this->reportKey}")
                            ->attachData($csv, $filename, ['mime' => 'text/csv']);
                    }
                );
            } catch (\Throwable $e) {
                Log::warning('CRM report email failed', ['to' => $email, 'error' => $e->getMessage()]);
            }
        }
    }

    private function toCsv(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return (string) $out;
    }
}
