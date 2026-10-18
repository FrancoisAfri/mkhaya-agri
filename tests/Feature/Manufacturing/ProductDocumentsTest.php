<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\DocumentManagement\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * MFG-002: "Maintain product versions, drawings, work instructions and technical documents" and
 * "store ... images".
 *
 * The files live in Document Management, which owns storage, encryption, versions and who may
 * open what. Manufacturing registers what each file is FOR -- this is the drawing, that is the work
 * instruction -- and must never become a way round the library's own permissions.
 */
class ProductDocumentsTest extends ManufacturingTestCase
{
    private function enableDocumentManagement(): void
    {
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Document Management', 'active' => 1,
                'category_id' => DB::table('security_categories')->where('name', 'Operations & Support')->value('id'),
                'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('company_modules')->updateOrInsert(
            ['company_id' => $this->company->id, 'module_code' => 'document_management'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]
        );
        Cache::flush();

        foreach (['document.view', 'document.upload'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->engineer->givePermissionTo(['document.view', 'document.upload']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function fileFor($item, string $title): Document
    {
        return $this->asCompany($this->company, function () use ($item, $title) {
            $doc = Document::create([
                'company_id' => $this->company->id, 'title' => $title, 'created_by' => $this->engineer->id,
            ]);
            $item->attachDocument($doc);

            return $doc;
        });
    }

    public function test_without_document_management_the_panel_is_absent_and_the_register_still_works(): void
    {
        $item = $this->product('NODMS');

        $props = $this->actingAs($this->engineer)
            ->get(route('manufacturing.engineering.products.show', $item->uuid))
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['documents_panel']['available']);

        // External references need nothing from the library.
        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.products.documents.store', $item->uuid), [
                'kind' => 'DRAWING', 'title' => 'Customer drawing', 'external_reference' => 'CUST-1001',
            ])->assertSessionHas('success');
    }

    public function test_with_document_management_the_panel_addresses_the_product_by_uuid(): void
    {
        $this->enableDocumentManagement();
        $item = $this->product('WITHDMS');
        $this->fileFor($item, 'GA drawing rev C');

        $panel = $this->actingAs($this->engineer->fresh())
            ->get(route('manufacturing.engineering.products.show', $item->uuid))
            ->assertOk()->viewData('page')['props']['documents_panel'];

        $this->assertTrue($panel['available']);
        $this->assertSame('product', $panel['relation']);
        // The public identifier, never the internal one -- it goes into the upload URL.
        $this->assertSame($item->uuid, $panel['record']);
        $this->assertSame(['GA drawing rev C'], array_column($panel['documents'], 'title'));
    }

    public function test_a_register_entry_can_point_at_a_file_filed_against_the_product(): void
    {
        $this->enableDocumentManagement();
        $item = $this->product('DRW');
        $file = $this->fileFor($item, 'Assembly drawing');

        $this->actingAs($this->engineer->fresh())
            ->post(route('manufacturing.engineering.products.documents.store', $item->uuid), [
                'kind' => 'DRAWING', 'title' => 'General arrangement', 'document_uuid' => $file->uuid,
            ])->assertSessionHas('success');

        $this->assertSame($file->id, (int) $item->documents()->value('document_id'));

        $row = $this->actingAs($this->engineer->fresh())
            ->get(route('manufacturing.engineering.products.show', $item->uuid))
            ->viewData('page')['props']['item']['documents'][0];

        $this->assertSame($file->uuid, $row['document_uuid']);
        $this->assertArrayNotHasKey('document_id', $row, 'The internal document id reached the page.');
    }

    public function test_a_register_entry_cannot_point_at_another_products_file(): void
    {
        $this->enableDocumentManagement();
        $mine = $this->product('MINE-P');
        $theirs = $this->product('THEIRS-P');
        $theirFile = $this->fileFor($theirs, 'Their drawing');

        $this->actingAs($this->engineer->fresh())
            ->post(route('manufacturing.engineering.products.documents.store', $mine->uuid), [
                'kind' => 'DRAWING', 'title' => 'Borrowed', 'document_uuid' => $theirFile->uuid,
            ])->assertSessionHas('error');

        $this->assertSame(0, $mine->documents()->count());
    }

    public function test_an_image_is_a_kind_of_product_document(): void
    {
        $item = $this->product('PIC');

        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.products.documents.store', $item->uuid), [
                'kind' => 'IMAGE', 'title' => 'Product photograph', 'external_reference' => 'DAM-77',
            ])->assertSessionHas('success');

        $this->assertSame('IMAGE', $item->documents()->value('kind'));
    }
}
