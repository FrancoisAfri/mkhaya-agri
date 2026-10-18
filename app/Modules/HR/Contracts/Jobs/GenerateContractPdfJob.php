<?php

namespace App\Modules\HR\Contracts\Jobs;

use App\Modules\HR\Contracts\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateContractPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(private readonly Contract $contract) {
        // Queueable already declares $afterCommit — a typed redeclaration is a
        // PHP fatal on class load. Set it here instead.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        if (!$this->contract->content) {
            Log::warning("GenerateContractPdf: contract #{$this->contract->id} has no content to render.");
            return;
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'pdf.contracts.contract',
            ['contract' => $this->contract]
        );

        $pdf->setPaper('A4', 'portrait');

        $path = "contracts/{$this->contract->id}/contract_{$this->contract->uuid}.pdf";

        Storage::disk('s3')->put($path, $pdf->output(), ['visibility' => 'private']);

        $this->contract->update(['pdf_path' => $path]);

        Log::info("GenerateContractPdf: PDF stored at {$path} for contract #{$this->contract->id}");
    }
}
