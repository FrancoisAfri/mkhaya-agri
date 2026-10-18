<?php

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Models\BiConnection;
use App\Modules\Analytics\Services\BiDatasets;
use App\Modules\Analytics\Services\DatabricksExporter;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send the datasets to the warehouse, off the request.
 *
 * Fifty thousand rows over a hundred HTTPS round trips is not something to do while somebody
 * watches a button spin, and a browser that gives up halfway leaves an export nobody can tell the
 * state of.
 *
 * The company id travels with the job rather than being read from whatever context the worker
 * happens to be in. A queue has no session, so `company_id` resolves to nothing there — and the
 * failure mode of getting that wrong is not an error, it is one tenant's rows written into another
 * tenant's warehouse, permanently and outside this platform's reach.
 */
class ExportToWarehouseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    /** @param  list<string>  $datasets */
    public function __construct(
        public int $companyId,
        public string $connectionUuid,
        public array $datasets,
    ) {}

    public function handle(TenantContext $tenants, DatabricksExporter $exporter): void
    {
        $tenants->run($this->companyId, function () use ($exporter) {
            $connection = BiConnection::where('uuid', $this->connectionUuid)
                ->where('company_id', $this->companyId)
                ->first();

            if ($connection === null || ! $connection->hasToken()) {
                Log::warning('BI export skipped: no usable connection.', [
                    'company' => $this->companyId, 'connection' => $this->connectionUuid,
                ]);

                return;
            }

            $errors = [];

            foreach ($this->datasets as $dataset) {
                try {
                    $rows = BiDatasets::rows($dataset, $this->companyId);
                    $exporter->export($connection, $dataset, $rows);
                } catch (\Throwable $e) {
                    /*
                     * One dataset failing does not stop the others.
                     *
                     * A customer exporting three datasets whose second one hits a permission
                     * problem should still get the first and third — otherwise a single warehouse
                     * grant makes the whole feature unavailable, and the report they actually
                     * wanted was the one that would have worked.
                     */
                    $errors[] = "{$dataset}: " . $e->getMessage();
                    Log::error('BI export failed for one dataset.', [
                        'company' => $this->companyId, 'dataset' => $dataset,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $connection->forceFill([
                'last_exported_at' => now(),
                // Kept on the connection so the settings screen can say what went wrong without
                // sending somebody to the server logs.
                'last_test_error'  => $errors === [] ? null : implode(' · ', $errors),
            ])->save();
        });
    }
}
