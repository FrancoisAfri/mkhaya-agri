<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\ConfirmExtractionAction;
use App\Modules\Assets\Actions\UploadExtractionDocumentAction;
use App\Modules\Assets\Models\AssetDocumentExtraction;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentExtractionTest extends AssetTestCase
{
    public function test_upload_stores_file_and_creates_extraction_record(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf');

        $extraction = (new UploadExtractionDocumentAction())
            ->execute($file, $this->assetManager->id);

        $this->assertDatabaseHas('asset_document_extractions', [
            'id'          => $extraction->id,
            'uploaded_by' => $this->assetManager->id,
            'file_name'   => 'invoice.pdf',
            'mime_type'   => 'application/pdf',
        ]);

        // The placeholder extractor advances status to 'extracted'
        $this->assertSame('extracted', $extraction->refresh()->extraction_status->value ?? $extraction->extraction_status);
    }

    public function test_upload_extraction_populates_extracted_fields(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('purchase-order-2026.pdf', 200, 'application/pdf');

        $extraction = (new UploadExtractionDocumentAction())
            ->execute($file, $this->assetManager->id);

        $extraction->refresh();
        $this->assertNotNull($extraction->extracted_fields);
        $this->assertArrayHasKey('asset_name', (array) $extraction->extracted_fields);
        $this->assertArrayHasKey('serial_number', (array) $extraction->extracted_fields);
    }

    public function test_confirmed_extraction_applies_fields_to_asset(): void
    {
        $asset = Assets::factory()->create(['name' => 'Original Name']);
        $extraction = AssetDocumentExtraction::factory()->extracted()->create([
            'asset_id' => $asset->id,
        ]);

        (new ConfirmExtractionAction())->execute($extraction, [
            'name'          => 'Confirmed Asset Name',
            'serial_number' => 'SN-CONFIRMED-001',
            'purchase_cost' => 19999.99,
        ], $this->assetManager->id);

        $extraction->refresh();
        $this->assertSame('confirmed', $extraction->extraction_status->value ?? $extraction->extraction_status);
        $this->assertNotNull($extraction->confirmed_at);
        $this->assertSame($this->assetManager->id, (int) $extraction->confirmed_by);

        $this->assertDatabaseHas('assets', [
            'id'            => $asset->id,
            'name'          => 'Confirmed Asset Name',
            'serial_number' => 'SN-CONFIRMED-001',
        ]);
    }

    public function test_confirm_extraction_without_linked_asset_does_not_create_one(): void
    {
        $extraction = AssetDocumentExtraction::factory()->extracted()->create(['asset_id' => null]);
        $beforeCount = Assets::count();

        (new ConfirmExtractionAction())->execute($extraction, [
            'name' => 'Some Asset',
        ], $this->assetManager->id);

        $this->assertSame($beforeCount, Assets::count());
    }

    public function test_upload_endpoint_requires_authentication(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('doc.pdf', 200, 'application/pdf');

        $this->post(route('assets.document-extractions.store'), ['file' => $file])
            ->assertRedirect(route('login'));
    }

    public function test_upload_endpoint_rejects_non_pdf_files(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->image('not-a-pdf.png');

        $this->actingAs($this->assetManager)
            ->post(route('assets.document-extractions.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }
}
