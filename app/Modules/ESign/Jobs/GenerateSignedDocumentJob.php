<?php

namespace App\Modules\ESign\Jobs;

use App\Modules\ESign\Models\AuditEntry;
use App\Modules\ESign\Models\Envelope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateSignedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(private readonly Envelope $envelope) {
        // Queueable already declares $afterCommit — a typed redeclaration is a
        // PHP fatal on class load. Set it here instead.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $envelope = $this->envelope->fresh(['signers.otpChallenges', 'auditEntries', 'createdBy']);

        if (!$envelope) {
            Log::warning("GenerateSignedDocument: envelope #{$this->envelope->id} not found.");
            return;
        }

        $signers      = $envelope->signers()->with('otpChallenges')->orderBy('signing_order')->get();
        $auditEntries = $envelope->auditEntries()->orderBy('created_at')->get();

        $pdf = Pdf::loadView('pdf.esign.signature-certificate', compact('envelope', 'signers', 'auditEntries'));
        $pdf->setPaper('A4', 'portrait');

        $disk = $envelope->disk ?? 'local';
        $path = "esign/{$envelope->id}/certificate_{$envelope->uuid}.pdf";

        Storage::disk($disk)->put($path, $pdf->output(), ['visibility' => 'private']);

        /*
         * forceFill, because `signed_document_path` is deliberately not fillable.
         *
         * Envelope guards its lifecycle columns against mass assignment — correctly — and
         * update() honours that guard by silently discarding the attribute. So this line ran, the
         * certificate was written to disk, the audit entry was created, the log line said it had
         * worked, and the column stayed null. Everything downstream keys off that column, so the
         * signed copy was never filed back against the document it belonged to.
         */
        $envelope->forceFill(['signed_document_path' => $path])->save();

        AuditEntry::create([
            'envelope_id' => $envelope->id,
            'signer_id'   => null,
            'event'       => 'certificate_generated',
            'actor_name'  => 'System',
            'actor_email' => null,
            'ip_address'  => null,
            'user_agent'  => null,
            'metadata'    => ['path' => $path, 'disk' => $disk],
            'created_at'  => now(),
        ]);

        Log::info("GenerateSignedDocument: certificate stored at {$path} for envelope #{$envelope->id}");
    }
}
