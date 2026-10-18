<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\AI\Registry\AgentRegistry;
use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAiInsight;
use App\Modules\Operations\DocumentManagement\Models\DocumentExtraction;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\DocumentFieldExtractor;
use App\Modules\Operations\DocumentManagement\Services\TextExtractor;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reading a scan, and reading the fields out of what it says.
 *
 * The OCR half is checked against a real image drawn at test time rather than a fixture, so it
 * exercises the actual engine. The field half is checked against a recording agent, because the
 * thing worth guarding is not the model's accuracy — it is that nothing it says reaches the
 * document until a person accepts it.
 */
class OcrAndFieldExtractionTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ocr+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->user);

        Storage::fake('documents');

        DocumentSetting::query()->delete();
        DocumentSetting::create(['ai_enabled' => true, 'max_file_size_mb' => 25]);
    }

    /** A real PNG with real words on it, drawn here so the OCR engine has something to read. */
    private function scanBytes(string $line1, string $line2 = ''): string
    {
        $image = imagecreatetruecolor(720, 170);
        imagefilledrectangle($image, 0, 0, 720, 170, imagecolorallocate($image, 255, 255, 255));
        $black = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 20, 45, $line1, $black);

        if ($line2 !== '') {
            imagestring($image, 5, 20, 85, $line2, $black);
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function document(string $bytes, string $extension, string $mime, string $title = 'A document'): Document
    {
        $document = Document::create([
            'title' => $title, 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ]);

        $path = 'ocr/' . Str::random(12) . '.' . $extension;
        Storage::disk('documents')->put($path, $bytes);

        $version = DocumentVersion::create([
            'uuid' => (string) Str::uuid(), 'document_id' => $document->id,
            'version' => 1, 'major' => 1, 'minor' => 0,
            'disk' => 'documents', 'path' => $path,
            'original_name' => 'file.' . $extension, 'stored_name' => basename($path),
            'extension' => $extension, 'mime' => $mime, 'size' => strlen($bytes),
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        return $document->fresh();
    }

    // ── OCR ──────────────────────────────────────────────────────────────────

    public function test_a_scanned_image_is_read_by_ocr(): void
    {
        $extractor = app(TextExtractor::class);

        if (! $extractor->ocrAvailable()) {
            $this->markTestSkipped('No OCR engine installed on this machine.');
        }

        $document = $this->document($this->scanBytes('TAX CLEARANCE CERTIFICATE'), 'png', 'image/png');

        $extraction = $extractor->extractCurrent($document);

        $this->assertSame(TextExtractor::EXTRACTED, $extraction->status);
        $this->assertSame('ocr', $extraction->method);
        $this->assertStringContainsStringIgnoringCase('CLEARANCE', (string) $extraction->text);
    }

    public function test_a_scan_becomes_searchable_by_its_contents(): void
    {
        // The point of installing OCR at all: a certificate somebody photographed is findable by
        // the words printed on it, not only by whatever the uploader typed as a title.
        $extractor = app(TextExtractor::class);

        if (! $extractor->ocrAvailable()) {
            $this->markTestSkipped('No OCR engine installed on this machine.');
        }

        $document = $this->document($this->scanBytes('INSURANCE CERTIFICATE'), 'png', 'image/png', 'IMG_4471');
        $extractor->extractCurrent($document);

        $found = app(\App\Modules\Operations\DocumentManagement\Services\DocumentSearch::class)
            ->query($this->user, ['q' => 'insurance'])->pluck('title');

        $this->assertTrue($found->contains('IMG_4471'));
    }

    // ── Fields ───────────────────────────────────────────────────────────────

    /** An agent that answers with a fixed reading and records what it was asked. */
    private function agentReturning(array $payload): object
    {
        $recorder = new class {
            public ?\App\Shared\DTOs\AgentRequest $last = null;
        };

        $agent = new class($recorder, $payload) implements \App\Core\AI\Contracts\AIAgentInterface {
            public function __construct(private readonly object $recorder, private readonly array $payload) {}

            public function ask(\App\Shared\DTOs\AgentRequest $request): \App\Shared\DTOs\AgentResponse
            {
                $this->recorder->last = $request;

                return new \App\Shared\DTOs\AgentResponse(
                    content: json_encode($this->payload), provider: 'spy', model: 'spy-1',
                    promptTokens: 1, completionTokens: 1, totalTokens: 2, durationMs: 1, success: true,
                );
            }

            public function stream(\App\Shared\DTOs\AgentRequest $request): \Generator { yield ''; }

            public function providerName(): string { return 'spy'; }
        };

        $registry = new class($agent) extends AgentRegistry {
            public function __construct(private readonly \App\Core\AI\Contracts\AIAgentInterface $agent) {}

            public function for(string $module): \App\Core\AI\Contracts\AIAgentInterface { return $this->agent; }

            public function forTask(string $module, ?string $task): \App\Core\AI\Contracts\AIAgentInterface { return $this->agent; }
        };

        $this->app->instance(AgentRegistry::class, $registry);

        return $recorder;
    }

    private function readableDocument(string $text = 'Tax clearance valid until 30 June 2027.'): Document
    {
        $document = $this->document($text, 'txt', 'text/plain', 'Tax clearance');

        DocumentExtraction::create([
            'document_id' => $document->id, 'version_id' => $document->current_version_id,
            'status' => TextExtractor::EXTRACTED, 'method' => 'plain',
            'text' => $text, 'char_count' => strlen($text), 'word_count' => str_word_count($text),
        ]);

        return $document->fresh();
    }

    public function test_a_reading_is_stored_as_a_suggestion_and_changes_nothing(): void
    {
        /*
         * The rule the whole feature rests on. A model reading "valid until 30/06/2027" off a scan
         * is right most of the time, and the times it is wrong look exactly the same — an expiry
         * date filled in silently from a misread produces a certificate the organisation believes
         * is valid for another year.
         */
        $this->agentReturning(['document_number' => 'TCS-9911', 'expiry_date' => '2027-06-30']);

        $document = $this->readableDocument();

        $result = app(DocumentFieldExtractor::class)->suggest($document);

        $this->assertNotNull($result['insight']);
        $this->assertNull($document->fresh()->expiry_date, 'Nothing may reach the document before a person accepts it.');
        // The module assigns its own document number on upload, so the test is that the suggested
        // one has not replaced it — not that the field is empty.
        $this->assertNotSame('TCS-9911', $document->fresh()->document_number);
    }

    public function test_accepting_a_reading_writes_only_the_accepted_fields(): void
    {
        $this->agentReturning(['document_number' => 'TCS-9911', 'expiry_date' => '2027-06-30']);

        $document = $this->readableDocument();
        $insight = app(DocumentFieldExtractor::class)->suggest($document)['insight'];

        app(DocumentFieldExtractor::class)->apply($insight, ['expiry_date']);

        $document = $document->fresh();

        $this->assertSame('2027-06-30', $document->expiry_date?->toDateString());
        $this->assertNotSame('TCS-9911', $document->document_number, 'A field nobody ticked keeps the value it had.');
        $this->assertNotNull($insight->fresh()->applied_at);
    }

    public function test_a_field_the_model_never_suggested_cannot_be_smuggled_in(): void
    {
        // The screen is a confirmation, so the request that comes back from it must not be able to
        // carry a value the reading never proposed.
        $this->agentReturning(['expiry_date' => '2027-06-30']);

        $document = $this->readableDocument();
        $insight = app(DocumentFieldExtractor::class)->suggest($document)['insight'];

        $result = app(DocumentFieldExtractor::class)->apply($insight, ['document_number', 'title']);

        $this->assertSame([], $result['applied']);
        $this->assertNotNull($result['error']);
    }

    public function test_a_malformed_date_is_dropped_rather_than_written(): void
    {
        /*
         * A model asked for YYYY-MM-DD returns "2027" or "30 June 2027" often enough to matter, and
         * a date column given either is a crash or, worse, a silently wrong year.
         */
        $this->agentReturning(['expiry_date' => '30 June 2027', 'document_date' => '2027-02-30']);

        $result = app(DocumentFieldExtractor::class)->suggest($this->readableDocument());

        $data = $result['insight']?->data ?? [];

        $this->assertArrayNotHasKey('expiry_date', $data, 'A date not in the requested format is not a date.');
        $this->assertArrayNotHasKey('document_date', $data, '30 February is not a day.');
    }

    public function test_a_dismissed_reading_is_not_offered_again(): void
    {
        $this->agentReturning(['expiry_date' => '2027-06-30']);

        $document = $this->readableDocument();
        $extractor = app(DocumentFieldExtractor::class);

        $insight = $extractor->suggest($document)['insight'];
        $this->assertNotNull($extractor->pendingFor($document->fresh()));

        $extractor->dismiss($insight);

        $this->assertNull($extractor->pendingFor($document->fresh()));
    }

    public function test_the_document_text_is_what_is_read_and_not_the_title(): void
    {
        $spy = $this->agentReturning(['expiry_date' => '2027-06-30']);

        app(DocumentFieldExtractor::class)->suggest($this->readableDocument('Certificate number ABC-123 applies.'));

        $this->assertNotNull($spy->last);
        $this->assertStringContainsString('Certificate number ABC-123', $spy->last->prompt);
    }

    public function test_applying_a_reading_is_recorded_as_having_come_from_one(): void
    {
        // A year from now "who set this expiry date" has one answer either way, and it should say
        // whether a person typed it or accepted it.
        $this->agentReturning(['expiry_date' => '2027-06-30']);

        $document = $this->readableDocument();
        $insight = app(DocumentFieldExtractor::class)->suggest($document)['insight'];

        app(DocumentFieldExtractor::class)->apply($insight, ['expiry_date']);

        $event = \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::query()
            ->where('document_id', $document->id)
            ->where('action', \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::UPDATED)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('extracted', $event->detail['source'] ?? null);
    }
}
