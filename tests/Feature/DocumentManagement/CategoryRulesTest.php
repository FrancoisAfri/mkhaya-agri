<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategoryField;
use App\Modules\Operations\DocumentManagement\Services\CategoryRules;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Rules that belong to a kind of document rather than to the repository.
 *
 * Every organisation has some and none of them fit a single global setting. A contract needs a
 * counterparty and an end date; a certificate needs an issuer and an expiry; a policy needs neither
 * and does need approval before anybody reads it. Requiring all of that of everything makes filing
 * a scanned receipt an ordeal; requiring none of it leaves the contracts register with holes found
 * only when somebody needs the counterparty.
 */
class CategoryRulesTest extends TestCase
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

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'cat+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        $user = $user->fresh();

        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function uploader(): User
    {
        return $this->user('document.view', 'document.upload', 'document.update');
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\ncontract\n%%EOF");

        return new UploadedFile($path, 'contract.pdf', 'application/pdf', null, true);
    }

    private function category(array $attributes = []): DocumentCategory
    {
        return DocumentCategory::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Contracts ' . Str::random(4),
            'slug' => 'contracts-' . Str::random(6),
        ], $attributes));
    }

    private function field(DocumentCategory $category, array $attributes = []): DocumentCategoryField
    {
        return DocumentCategoryField::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'category_id' => $category->id,
            'key' => 'counterparty',
            'label' => 'Counterparty',
            'type' => DocumentCategoryField::TYPE_TEXT,
        ], $attributes));
    }

    private function rules(): CategoryRules
    {
        return app(CategoryRules::class);
    }

    // ── §1 Extra fields ──────────────────────────────────────────────────────

    public function test_a_required_field_stops_the_upload_and_says_which(): void
    {
        /*
         * "Counterparty is needed" is something somebody can act on. "Invalid" sends them hunting
         * through a form for whichever box they left empty.
         */
        $category = $this->category();
        $this->field($category, ['is_required' => true, 'label' => 'Counterparty']);

        $this->actingAs($this->uploader());
        $result = app(DocumentService::class)->store($this->pdf(), ['category_id' => $category->id]);

        $this->assertNull($result['document']);
        $this->assertStringContainsString('Counterparty', (string) $result['error']);
    }

    public function test_an_optional_field_left_blank_is_fine(): void
    {
        // An upload should never be blocked on knowing something the person filing it does not.
        $category = $this->category();
        $this->field($category, ['is_required' => false]);

        $this->actingAs($this->uploader());
        $result = app(DocumentService::class)->store($this->pdf(), ['category_id' => $category->id]);

        $this->assertNotNull($result['document'], $result['error'] ?? '');
    }

    public function test_a_document_in_no_category_asks_for_nothing(): void
    {
        // The common case, and the one that must stay frictionless: a scanned receipt.
        $this->actingAs($this->uploader());

        $this->assertNotNull(app(DocumentService::class)->store($this->pdf(), [])['document']);
    }

    public function test_the_values_are_stored_and_read_back(): void
    {
        $category = $this->category();
        $this->field($category, ['key' => 'counterparty', 'label' => 'Counterparty']);

        $this->actingAs($this->uploader());
        $document = app(DocumentService::class)->store($this->pdf(), [
            'category_id' => $category->id,
            'custom_fields' => ['counterparty' => 'Acme Holdings'],
        ])['document'];

        $this->assertSame('Acme Holdings', $document->fresh()->custom_fields['counterparty']);
    }

    public function test_values_the_category_never_asked_for_are_dropped(): void
    {
        /*
         * Without this the column becomes whatever a form happened to post, and a field renamed
         * leaves its old values behind for ever with nothing to read them.
         */
        $category = $this->category();
        $this->field($category, ['key' => 'counterparty']);

        $this->actingAs($this->uploader());
        $document = app(DocumentService::class)->store($this->pdf(), [
            'category_id' => $category->id,
            'custom_fields' => ['counterparty' => 'Acme', 'smuggled' => 'anything at all'],
        ])['document'];

        $this->assertArrayNotHasKey('smuggled', $document->fresh()->custom_fields ?? []);
    }

    public function test_a_choice_that_is_not_on_the_list_is_not_kept(): void
    {
        // Keeping it would leave a value rendering as an option nobody can select again.
        $category = $this->category();
        $this->field($category, [
            'key' => 'risk',
            'type' => DocumentCategoryField::TYPE_SELECT,
            'options' => ['low', 'medium', 'high'],
        ]);

        $cleaned = $this->rules()->clean($category, ['risk' => 'catastrophic']);

        // array_key_exists rather than ??, which would coalesce the null this is checking for.
        $this->assertTrue(array_key_exists('risk', $cleaned));
        $this->assertNull($cleaned['risk']);
    }

    public function test_a_required_checkbox_is_not_treated_as_missing_when_unticked(): void
    {
        /*
         * An unticked box is a legitimate "no". Requiring one would mean the only way to file the
         * document is to answer yes, which is not a question worth asking.
         */
        $category = $this->category();
        $this->field($category, [
            'key' => 'signed',
            'label' => 'Signed',
            'type' => DocumentCategoryField::TYPE_BOOLEAN,
            'is_required' => true,
        ]);

        $this->assertTrue($this->rules()->validate($category, ['signed' => false])['ok']);
    }

    public function test_a_value_left_by_a_removed_field_stops_being_shown(): void
    {
        /*
         * The values stay in the document's own column — recoverable if somebody puts the field
         * back, which stripping them out would not be — but they are read through the category's
         * current fields, so nothing surfaces as a nameless row.
         */
        $category = $this->category();
        $field = $this->field($category, ['key' => 'counterparty']);

        $this->actingAs($this->uploader());
        $document = app(DocumentService::class)->store($this->pdf(), [
            'category_id' => $category->id,
            'custom_fields' => ['counterparty' => 'Acme'],
        ])['document'];

        $field->delete();

        $this->assertSame([], $this->rules()->describe($document->fresh()));
        $this->assertSame('Acme', $document->fresh()->custom_fields['counterparty'], 'The value itself is kept.');
    }

    // ── §26 Numbering ────────────────────────────────────────────────────────

    public function test_a_document_gets_its_number_from_the_categorys_pattern(): void
    {
        $category = $this->category(['number_pattern' => 'CTR-{YYYY}-{####}', 'number_next' => 41]);

        $this->actingAs($this->uploader());
        $document = app(DocumentService::class)->store($this->pdf(), ['category_id' => $category->id])['document'];

        $this->assertSame('CTR-' . now()->format('Y') . '-0041', $document->fresh()->document_number);
    }

    public function test_the_sequence_moves_on(): void
    {
        $category = $this->category(['number_pattern' => 'CTR-{####}', 'number_next' => 1]);

        $this->actingAs($this->uploader());
        $service = app(DocumentService::class);

        $first = $service->store($this->pdf(), ['category_id' => $category->id])['document'];
        $second = $service->store($this->pdf(), ['category_id' => $category->id])['document'];

        $this->assertSame('CTR-0001', $first->fresh()->document_number);
        $this->assertSame('CTR-0002', $second->fresh()->document_number);
    }

    public function test_a_number_that_was_typed_in_is_kept(): void
    {
        /*
         * A document arriving with its number already printed on it keeps that number. The pattern
         * is for the ones being created here, not for relabelling what somebody else issued.
         */
        $category = $this->category(['number_pattern' => 'CTR-{####}']);

        $this->actingAs($this->uploader());
        $document = app(DocumentService::class)->store($this->pdf(), [
            'category_id' => $category->id,
            'document_number' => 'SUPPLIER-REF-9912',
        ])['document'];

        $this->assertSame('SUPPLIER-REF-9912', $document->fresh()->document_number);
    }

    public function test_a_category_with_no_pattern_leaves_the_companys_own_numbering_alone(): void
    {
        /*
         * The module already numbers everything DOC-YYYY-00001 when nobody supplies a number, and
         * that has to keep working. A category's pattern is an override for one kind of document,
         * not a replacement for the scheme every other document relies on.
         */
        $category = $this->category();

        $this->actingAs($this->uploader());
        $document = app(DocumentService::class)->store($this->pdf(), ['category_id' => $category->id])['document'];

        $this->assertStringStartsWith('DOC-' . now()->format('Y') . '-', (string) $document->fresh()->document_number);
    }

    public function test_a_categorys_pattern_overrides_the_companys_default(): void
    {
        $category = $this->category(['number_pattern' => 'CTR-{####}', 'number_next' => 3]);

        $this->actingAs($this->uploader());
        $document = app(DocumentService::class)->store($this->pdf(), ['category_id' => $category->id])['document'];

        $this->assertSame('CTR-0003', $document->fresh()->document_number);
    }

    public function test_the_padding_follows_however_many_hashes_were_written(): void
    {
        $category = $this->category(['number_pattern' => 'INV-{######}', 'number_next' => 7]);

        $this->assertSame('INV-000007', $this->rules()->nextNumber($category));
    }

    public function test_a_pattern_with_no_placeholder_is_refused(): void
    {
        /*
         * It would hand every document the same number — which is worse than no numbering at all,
         * because it looks like numbering until somebody tries to find one.
         */
        $admin = $this->user('document.view', 'document.settings.manage');
        $category = $this->category();

        $this->actingAs($admin)
            ->put(route('document-management.categories.update', $category->uuid), [
                'number_pattern' => 'CONTRACT',
            ])
            ->assertSessionHasErrors('number_pattern');
    }

    public function test_the_counter_can_be_set_to_carry_on_from_a_spreadsheet(): void
    {
        // Somebody migrating needs to continue from 41 rather than start at 1 and collide with
        // everything already numbered by hand.
        $admin = $this->user('document.view', 'document.settings.manage');
        $category = $this->category();

        $this->actingAs($admin)
            ->put(route('document-management.categories.update', $category->uuid), [
                'number_pattern' => 'CTR-{####}',
                'number_next' => 41,
            ])
            ->assertRedirect();

        $this->assertSame('CTR-0041', $this->rules()->nextNumber($category->fresh()));
    }

    // ── §24 Fields set from the settings screen ──────────────────────────────

    public function test_fields_are_saved_against_the_category(): void
    {
        $admin = $this->user('document.view', 'document.settings.manage');
        $category = $this->category();

        $this->actingAs($admin)
            ->put(route('document-management.categories.update', $category->uuid), [
                'fields' => [
                    ['key' => 'counterparty', 'label' => 'Counterparty', 'type' => 'text', 'required' => true],
                    ['key' => 'value', 'label' => 'Contract value', 'type' => 'number'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, DocumentCategoryField::where('category_id', $category->id)->count());
    }

    public function test_renaming_a_label_does_not_orphan_the_values_recorded_under_it(): void
    {
        /*
         * The key is what every value is stored under, and people rename labels far more often than
         * they mean to change what a field is. Rebuilding the fields from scratch on each save
         * would lose every value already given.
         */
        $admin = $this->user('document.view', 'document.settings.manage');
        $category = $this->category();
        $this->field($category, ['key' => 'counterparty', 'label' => 'Counterparty']);

        $this->actingAs($this->uploader());
        $document = app(DocumentService::class)->store($this->pdf(), [
            'category_id' => $category->id,
            'custom_fields' => ['counterparty' => 'Acme'],
        ])['document'];

        $this->actingAs($admin)
            ->put(route('document-management.categories.update', $category->uuid), [
                'fields' => [['key' => 'counterparty', 'label' => 'Other party', 'type' => 'text']],
            ])
            ->assertRedirect();

        $described = $this->rules()->describe($document->fresh());

        $this->assertSame('Other party', $described[0]['label']);
        $this->assertSame('Acme', $described[0]['value'], 'The value survives the rename.');
    }

    public function test_a_field_dropped_from_the_list_is_removed(): void
    {
        $admin = $this->user('document.view', 'document.settings.manage');
        $category = $this->category();
        $this->field($category, ['key' => 'counterparty']);
        $this->field($category, ['key' => 'value', 'label' => 'Value', 'type' => 'number']);

        $this->actingAs($admin)
            ->put(route('document-management.categories.update', $category->uuid), [
                'fields' => [['key' => 'counterparty', 'label' => 'Counterparty', 'type' => 'text']],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['counterparty'],
            DocumentCategoryField::where('category_id', $category->id)->pluck('key')->all(),
        );
    }

    public function test_setting_up_fields_needs_the_right_to_change_settings(): void
    {
        $category = $this->category();

        $this->actingAs($this->uploader())
            ->put(route('document-management.categories.update', $category->uuid), [
                'fields' => [['key' => 'sneaky', 'label' => 'Sneaky', 'type' => 'text']],
            ])
            ->assertForbidden();
    }

    // ── Editing afterwards ───────────────────────────────────────────────────

    public function test_the_category_and_its_values_can_be_corrected_in_one_save(): void
    {
        /*
         * Changing the category and the values together is the ordinary way somebody fixes a
         * misfiled document. Validating against the category it is leaving would refuse it.
         */
        $wrong = $this->category(['name' => 'Receipts ' . Str::random(4)]);
        $right = $this->category(['name' => 'Contracts ' . Str::random(4)]);
        $this->field($right, ['key' => 'counterparty', 'label' => 'Counterparty', 'is_required' => true]);

        $uploader = $this->uploader();
        $this->actingAs($uploader);

        $document = app(DocumentService::class)->store($this->pdf(), ['category_id' => $wrong->id])['document'];

        $this->put(route('document-management.documents.update', $document->uuid), [
            'category' => $right->uuid,
            'custom_fields' => ['counterparty' => 'Acme'],
        ])->assertRedirect();

        $document = $document->fresh();

        $this->assertSame($right->id, (int) $document->category_id);
        $this->assertSame('Acme', $document->custom_fields['counterparty']);
    }
}
