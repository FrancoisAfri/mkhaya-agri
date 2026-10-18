<?php

namespace App\Console\Commands\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentExtraction;
use App\Modules\Operations\DocumentManagement\Services\TextExtractor;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Reads the documents that were uploaded before anything was reading them.
 *
 * New uploads are extracted as they arrive. This is for everything already in the repository, which
 * on an existing installation is all of it — and until it has run, searching by content finds
 * nothing and looks broken rather than empty.
 *
 * Runs per tenant, for the reason every sweep in this module does: row-level security only engages
 * once the session knows which company it is acting for.
 *
 *   php artisan documents:extract-text --dry-run
 *   php artisan documents:extract-text --company=1
 *   php artisan documents:extract-text --retry-failed
 */
class ExtractDocumentTextCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'documents:extract-text
        {--company= : Only this company.}
        {--limit=500 : Stop after this many documents per company.}
        {--retry-failed : Also re-read the ones that failed last time.}
        {--dry-run : Report what would be read without reading anything.}';

    protected $description = 'Extract searchable text from documents that have not been read yet.';

    public function handle(TenantContext $tenants, TextExtractor $extractor): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        if (! $extractor->ocrAvailable()) {
            // Said once, up front. Otherwise a run over a pile of scans reports hundreds of
            // documents with no text and reads as a fault in this command.
            $this->warn('No OCR engine on this server: scanned documents will be recorded as needing OCR, not as empty.');
        }

        $totals = ['read' => 0, 'text' => 0, 'no_text_layer' => 0, 'unsupported' => 0, 'failed' => 0];

        $this->eachTenant($tenants, function () use ($extractor, $limit, $dryRun, &$totals) {
            $pending = $this->pending($limit);

            if ($dryRun) {
                $this->line('  ' . $pending->count() . ' document(s) would be read');

                return;
            }

            foreach ($pending as $document) {
                $extraction = $extractor->extractCurrent($document);

                if (! $extraction) {
                    continue;
                }

                $totals['read']++;

                match ($extraction->status) {
                    TextExtractor::EXTRACTED => $totals['text']++,
                    TextExtractor::NO_TEXT_LAYER, TextExtractor::OCR_UNAVAILABLE => $totals['no_text_layer']++,
                    TextExtractor::UNSUPPORTED => $totals['unsupported']++,
                    TextExtractor::FAILED => $totals['failed']++,
                    default => null,
                };
            }

            $this->line('  ' . $pending->count() . ' document(s) read');
        });

        if (! $dryRun) {
            $this->info(sprintf(
                'Read %d document(s): %d with text, %d needing OCR, %d unsupported, %d failed.',
                $totals['read'], $totals['text'], $totals['no_text_layer'],
                $totals['unsupported'], $totals['failed'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Documents whose current version has never been read — or was read and failed, if asked.
     *
     * Keyed on the version rather than the document, so a document whose file was replaced is
     * picked up again even though an older extraction exists for it.
     */
    private function pending(int $limit)
    {
        return Document::query()
            ->whereNotNull('current_version_id')
            ->where(function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('document_extractions')
                        ->whereColumn('document_extractions.version_id', 'documents.current_version_id');
                });

                if ($this->option('retry-failed')) {
                    $q->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('document_extractions')
                            ->whereColumn('document_extractions.version_id', 'documents.current_version_id')
                            ->where('document_extractions.status', TextExtractor::FAILED);
                    });
                }
            })
            ->limit($limit)
            ->get();
    }
}
