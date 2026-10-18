<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\Models\AiProfile;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\CandidateDocument;
use App\Modules\HR\Recruitment\Services\CvParsingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ParseCandidateCv implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int  $tries       = 3;
    public int  $timeout     = 180;


    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly Candidate         $candidate,
        public readonly CandidateDocument $document,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $candidate = $this->candidate->fresh();
        $document  = $this->document->fresh();

        if (!$document || !$document->isVirusClean()) {
            Log::warning("ParseCandidateCv skipped: document #{$document?->id} not virus-clean.");
            return;
        }

        if (!$candidate->hasAiConsent()) {
            Log::warning("ParseCandidateCv skipped: candidate #{$candidate->id} has not given AI consent.");
            return;
        }

        // Retrieve from private S3
        if (!Storage::disk(config('filesystems.default'))->exists($document->path)) {
            Log::error("ParseCandidateCv: S3 file not found for document #{$document->id}.");
            $this->fail(new \RuntimeException("CV file missing from S3."));
            return;
        }

        $tempPath = null;
        try {
            // Download file from S3 to a local temp path for parsing
            $fileContents = Storage::disk(config('filesystems.default'))->get($document->path);
            $tempPath = sys_get_temp_dir() . '/cv_' . $candidate->id . '_' . uniqid() . '_' . basename($document->path);
            file_put_contents($tempPath, $fileContents);

            // Mark document as processing
            $document->parse_status = 'processing';
            $document->save();

            // Extract raw text from the CV file
            $parser  = new CvParsingService();
            $cvText  = $parser->extractText($tempPath, $document->mime_type);

            // Mark document extraction as completed (AI parsing tracked separately)
            $document->parse_status = 'completed';
            $document->save();

            // Dispatch AI parsing job with the extracted text
            ParseCvTextWithAiJob::dispatch($candidate, $document, $cvText)->onQueue('ai-heavy');
        } catch (\Throwable $e) {
            Log::error("ParseCandidateCv: text extraction failed for candidate #{$candidate->id}: {$e->getMessage()}");

            // Mark document as failed
            $document->parse_status = 'failed';
            $document->save();

            // Mark AI profile parse as failed
            AiProfile::updateOrCreate(
                ['candidate_id' => $candidate->id],
                ['parse_status' => 'failed', 'parse_error' => $e->getMessage()],
            );

            $this->fail($e);
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ParseCandidateCv failed for candidate #{$this->candidate->id}: {$e->getMessage()}");
        // Notify the recruiter that CV parsing failed
        // NotifyCvParsingFailed::dispatch($this->candidate)->onQueue('notifications');
    }
}
