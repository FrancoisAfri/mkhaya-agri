<?php

namespace Tests\Feature\ESign;

use App\Modules\ESign\Jobs\GenerateSignedDocumentJob;
use App\Modules\ESign\Models\AuditEntry;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateSignedDocumentJobTest extends ESignTestCase
{
    public function test_job_is_dispatched_when_envelope_completes(): void
    {
        Queue::fake();

        $token    = Str::random(64);
        $envelope = Envelope::factory()->sent()->create();
        $signer   = Signer::factory()->withToken($token)->signed()->create(['envelope_id' => $envelope->id]);

        // Directly call progressEnvelope via service reflection
        $service = app(\App\Modules\ESign\Services\ESignService::class);
        $method  = new \ReflectionMethod($service, 'progressEnvelope');
        $method->setAccessible(true);
        $method->invoke($service, $envelope);

        Queue::assertPushedOn('documents', GenerateSignedDocumentJob::class);
    }

    public function test_job_generates_pdf_and_stores_path(): void
    {
        Storage::fake('local');

        $envelope = Envelope::factory()->completed()->create(['created_by' => $this->hrUser->id]);
        Signer::factory()->signed()->create(['envelope_id' => $envelope->id]);

        (new GenerateSignedDocumentJob($envelope))->handle();

        $envelope->refresh();
        $this->assertNotNull($envelope->signed_document_path);
        Storage::disk('local')->assertExists($envelope->signed_document_path);
    }

    public function test_job_logs_certificate_generated_audit_entry(): void
    {
        Storage::fake('local');

        $envelope = Envelope::factory()->completed()->create(['created_by' => $this->hrUser->id]);
        Signer::factory()->signed()->create(['envelope_id' => $envelope->id]);

        (new GenerateSignedDocumentJob($envelope))->handle();

        $this->assertDatabaseHas('esign_audit_entries', [
            'envelope_id' => $envelope->id,
            'event'       => 'certificate_generated',
        ]);
    }
}
