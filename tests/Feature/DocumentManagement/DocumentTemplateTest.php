<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentGeneration;
use App\Modules\Operations\DocumentManagement\Models\DocumentTemplate;
use App\Modules\Operations\DocumentManagement\Services\GenerationService;
use App\Modules\Operations\DocumentManagement\Support\MergeFields;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Templates, and generating documents from them.
 *
 * The failure this feature exists to prevent is a contract typed by hand with the previous
 * employee's salary still in it. The failure it can *cause* is a contract generated with a blank
 * where the salary should be, which is worse — so most of these tests are about the gaps.
 */
class DocumentTemplateTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

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

        $this->actingAs(User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'tpl+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]));
    }

    private function generator(): GenerationService
    {
        return app(GenerationService::class);
    }

    private function template(string $body, array $attributes = []): DocumentTemplate
    {
        return DocumentTemplate::create(array_merge([
            'name' => 'Supplier agreement',
            'subject_relation' => 'supplier',
            'body' => $body,
        ], $attributes));
    }

    private function supplier(array $attributes = []): \App\Modules\SupplierManagement\Models\Supplier
    {
        return \App\Modules\SupplierManagement\Models\Supplier::create(array_merge([
            'company_name' => 'Acme Trading ' . Str::random(4),
            'company_id' => $this->companyId,
        ], $attributes));
    }

    // ── Merge fields ─────────────────────────────────────────────────────────

    public function test_a_field_is_replaced_with_the_records_value(): void
    {
        $supplier = $this->supplier(['company_name' => 'Bright Spark Electrical']);
        $values = MergeFields::resolve('supplier', $supplier)['values'];

        $this->assertSame(
            '<p>Agreement with Bright Spark Electrical</p>',
            MergeFields::fill('<p>Agreement with {{supplier.name}}</p>', $values),
        );
    }

    public function test_spacing_inside_the_braces_does_not_matter(): void
    {
        $values = MergeFields::resolve('supplier', $this->supplier(['company_name' => 'Acme']))['values'];

        // An author will type both forms, and being strict produces a document with the
        // placeholder still printed in it.
        $this->assertSame('Acme and Acme', MergeFields::fill('{{supplier.name}} and {{ supplier.name }}', $values));
    }

    public function test_a_field_nobody_can_answer_is_left_visible_rather_than_blanked(): void
    {
        $values = MergeFields::resolve('supplier', $this->supplier())['values'];

        // Left in the output so it shows up in the preview, rather than leaving a gap nobody can
        // explain later.
        $this->assertStringContainsString('{{supplier.nonsense}}', MergeFields::fill('{{supplier.nonsense}}', $values));
    }

    public function test_a_value_cannot_inject_markup_into_the_document(): void
    {
        $supplier = $this->supplier(['company_name' => '<script>alert(1)</script>Acme']);
        $values = MergeFields::resolve('supplier', $supplier)['values'];

        // A supplier name is data. The template is the only thing allowed to contribute markup.
        $this->assertStringNotContainsString('<script>', MergeFields::fill('{{supplier.name}}', $values));
    }

    public function test_only_fields_the_platform_can_answer_are_offered(): void
    {
        $fields = MergeFields::availableFor('supplier');

        $this->assertArrayHasKey('supplier.name', $fields);
        $this->assertArrayHasKey('company.name', $fields);
        // Exposing every column would put an id number one typo away from a printed contract.
        $this->assertArrayNotHasKey('supplier.bank_account_number', $fields);
    }

    public function test_a_typo_in_a_template_is_reported_before_anybody_generates_from_it(): void
    {
        $template = $this->template('<p>{{supplier.name}} — {{supplier.salery}}</p>');

        // Invisible otherwise until somebody prints a contract with the placeholder in it.
        $this->assertSame(['supplier.salery'], $template->unknownFields());
    }

    // ── Generating ───────────────────────────────────────────────────────────

    public function test_generating_produces_a_document_like_any_other(): void
    {
        $supplier = $this->supplier(['company_name' => 'Bright Spark']);
        $template = $this->template('<h1>Agreement</h1><p>Between us and {{supplier.name}}.</p>');

        $result = $this->generator()->generate($template, $supplier, ['allow_missing' => true]);
        $document = $result['document'];

        $this->assertNotNull($document, $result['error'] ?? '');
        $this->assertSame('application/pdf', $document->currentVersion->mime);
        $this->assertTrue(Storage::disk('documents')->exists($document->currentVersion->path));
        // Versioned, permissioned and searchable — nothing downstream needs to know it was
        // generated rather than uploaded.
        $this->assertSame('1.0', $document->currentVersion->label());
    }

    public function test_a_generated_document_is_linked_to_what_it_is_about(): void
    {
        $supplier = $this->supplier();
        $template = $this->template('<p>{{supplier.name}}</p>');

        $document = $this->generator()->generate($template, $supplier, ['allow_missing' => true])['document'];

        // It appears on the supplier's own record straight away rather than needing filing.
        $this->assertTrue($supplier->libraryDocuments()->pluck('id')->contains($document->id));
    }

    public function test_generation_stops_when_the_record_cannot_answer_a_field(): void
    {
        $supplier = $this->supplier(['vat_registration_number' => null]);
        $template = $this->template('<p>VAT: {{supplier.vat}}</p>');

        $result = $this->generator()->generate($template, $supplier);

        // A signed contract with a blank where a number should be is worse than one that was never
        // generated, so the default is to refuse and say which field.
        $this->assertNull($result['document']);
        $this->assertStringContainsString('supplier.vat', $result['error']);
    }

    public function test_the_refusal_can_be_overridden_when_the_gap_is_expected(): void
    {
        $template = $this->template('<p>VAT: {{supplier.vat}}</p>');

        $result = $this->generator()->generate($template, $this->supplier(), ['allow_missing' => true]);

        // Refusing forever would push people back to typing the document by hand.
        $this->assertNotNull($result['document']);
    }

    public function test_the_values_used_are_kept_as_they_were(): void
    {
        $supplier = $this->supplier(['company_name' => 'Original Name']);
        $template = $this->template('<p>{{supplier.name}}</p>');

        $this->generator()->generate($template, $supplier, ['allow_missing' => true]);
        $supplier->update(['company_name' => 'Renamed Later']);

        // A record that changes next month must not rewrite the contract generated this month.
        $generation = DocumentGeneration::latest('id')->first();
        $this->assertSame('Original Name', $generation->merge_values['supplier.name']);
    }

    public function test_a_generated_document_starts_as_a_draft(): void
    {
        $template = $this->template('<p>{{supplier.name}}</p>');

        $document = $this->generator()->generate($template, $this->supplier(), ['allow_missing' => true])['document'];

        // One that appeared already published would skip whatever review the organisation put in
        // place for that kind of document.
        $this->assertSame(Document::APPROVAL_DRAFT, $document->approval_status);
    }

    public function test_the_filename_pattern_takes_merge_fields_too(): void
    {
        $supplier = $this->supplier(['company_name' => 'Bright Spark']);
        $template = $this->template('<p>x</p>', [
            'filename_pattern' => 'Supplier agreement — {{supplier.name}}',
        ]);

        $document = $this->generator()->generate($template, $supplier, ['allow_missing' => true])['document'];

        // Rather than the third copy of a document with the same name as the other two.
        $this->assertSame('Supplier agreement — Bright Spark', $document->title);
    }

    public function test_generating_is_recorded_in_the_documents_history(): void
    {
        $template = $this->template('<p>{{supplier.name}}</p>');
        $document = $this->generator()->generate($template, $this->supplier(), ['allow_missing' => true])['document'];

        // "Where did this contract come from" has a real answer for a generated document.
        $this->assertTrue($document->events()->pluck('action')->contains(DocumentEvent::GENERATED));
    }

    public function test_a_preview_shows_the_gaps_without_producing_anything(): void
    {
        $template = $this->template('<p>{{supplier.name}} · {{supplier.vat}}</p>');

        $before = Document::count();
        $preview = $this->generator()->preview($template, $this->supplier(['vat_registration_number' => null]));

        $this->assertContains('supplier.vat', $preview['missing']);
        $this->assertSame($before, Document::count());
    }

    public function test_an_empty_template_is_refused(): void
    {
        $this->assertNull($this->generator()->generate($this->template(''), $this->supplier())['document']);
    }
}
