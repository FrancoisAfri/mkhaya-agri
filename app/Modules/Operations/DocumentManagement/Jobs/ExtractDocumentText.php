<?php

namespace App\Modules\Operations\DocumentManagement\Jobs;

use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\TextExtractor;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads a newly uploaded file in the background.
 *
 * Queued because extraction shells out to a PDF reader and a large scan can take seconds. Doing it
 * inside the upload request would make uploading a big document feel broken, and a reader that
 * hangs would take the request with it.
 *
 * The company is carried explicitly. A queue worker has no session, so without it every query in
 * here runs with no tenant set — which row-level security reads as no restriction, and the
 * extraction would be written with a null company_id that no tenant can then see.
 */
class ExtractDocumentText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        private readonly int $versionId,
        private readonly ?int $companyId,
    ) {
    }

    public function handle(TenantContext $tenants, TextExtractor $extractor): void
    {
        $tenants->run($this->companyId, function () use ($extractor) {
            $version = DocumentVersion::find($this->versionId);

            if (! $version) {
                // Deleted between upload and this running. Nothing to read and nothing wrong.
                return;
            }

            $extractor->extract($version);
        });
    }
}
