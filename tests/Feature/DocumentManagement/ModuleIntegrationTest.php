<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Support\DocumentModule;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The repository as the rest of the platform sees it.
 *
 * Document Management is an add-on. A company can be running Payroll and Fleet without it, so every
 * integration has to ask whether the module is active before it shows anything — a documents panel
 * that appears on an employee page and then refuses every action reads as a broken page rather than
 * as a module nobody bought. Most of what follows is about that gate.
 */
class ModuleIntegrationTest extends TestCase
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

        $this->activateModule(true);

        foreach (['document.view', 'document.upload'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'mi+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->user->givePermissionTo(['document.view', 'document.upload']);
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);
    }

    private function activateModule(bool $active): void
    {
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => DocumentModule::CODE],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => $active ? 1 : 0, 'created_at' => now(), 'updated_at' => now()],
        );

        Cache::flush();
    }

    /**
     * A record from another module, standing in for an employee or a vehicle.
     *
     * Created rather than found. Skipping when the database happens to have no employees would have
     * left most of this file silently not running, which is the failure mode these tests exist to
     * catch in the first place.
     */
    private function record(): object
    {
        return \App\Modules\HR\Hr\Models\HRPerson::query()->firstOrCreate(
            ['email' => 'integration+' . Str::random(6) . '@example.test'],
            ['first_name' => 'Integration', 'surname' => 'Fixture', 'company_id' => $this->companyId],
        );
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'Tax certificate', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    // ── The gate ─────────────────────────────────────────────────────────────

    public function test_the_panel_is_absent_where_the_module_is_not_active(): void
    {
        /*
         * The constraint that shapes every one of these integrations. A company running Payroll and
         * Fleet without this module must not be shown an upload button that leads to a 403.
         */
        $this->activateModule(false);

        $panel = DocumentModule::panelFor($this->record(), $this->user);

        $this->assertFalse($panel['available']);
        $this->assertSame([], $panel['documents']);
        $this->assertFalse($panel['can_upload']);
    }

    public function test_the_panel_is_absent_for_somebody_who_may_not_see_documents(): void
    {
        // An empty panel says "there is nothing here"; the truth is "you cannot be told".
        $outsider = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'out+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        $this->assertFalse(DocumentModule::panelFor($this->record(), $outsider)['available']);
    }

    public function test_the_panel_appears_where_the_module_is_active_and_the_person_may_see_documents(): void
    {
        $panel = DocumentModule::panelFor($this->record(), $this->user);

        $this->assertTrue($panel['available']);
        $this->assertSame(DocumentRelations::EMPLOYEE, $panel['relation']);
        $this->assertTrue($panel['can_upload']);
    }

    public function test_a_model_the_repository_was_never_taught_about_gets_no_panel(): void
    {
        // Better an absent panel than one that cannot say what it is showing documents for.
        $stranger = new class extends \Illuminate\Database\Eloquent\Model {
            use HasDocuments;

            protected $table = 'companies';
        };

        $this->assertFalse(DocumentModule::panelFor($stranger, $this->user)['available']);
    }

    // ── What the panel shows ─────────────────────────────────────────────────

    public function test_the_panel_lists_what_is_filed_against_that_record_and_nothing_else(): void
    {
        $record = $this->record();

        $mine = $this->document(['title' => 'Filed here']);
        $this->document(['title' => 'Filed nowhere']);

        $record->attachDocument($mine);

        $titles = collect(DocumentModule::panelFor($record, $this->user)['documents'])->pluck('title');

        $this->assertTrue($titles->contains('Filed here'));
        $this->assertFalse($titles->contains('Filed nowhere'));
    }

    public function test_the_panel_does_not_become_a_way_around_confidentiality(): void
    {
        /*
         * A record's page must not show a document somebody was refused everywhere else — otherwise
         * the way to read a confidential employee file is to open the employee.
         */
        $record = $this->record();

        $confidential = $this->document(['title' => 'Disciplinary record', 'is_confidential' => true]);
        $record->attachDocument($confidential);

        $titles = collect(DocumentModule::panelFor($record, $this->user)['documents'])->pluck('title');

        $this->assertFalse($titles->contains('Disciplinary record'));
    }

    // ── Filing from another module's page ────────────────────────────────────

    public function test_a_document_can_be_linked_from_the_record_it_belongs_to(): void
    {
        $record = $this->record();
        $document = $this->document(['title' => 'Signed contract']);

        $this->post(route('document-management.records.attach', [DocumentRelations::EMPLOYEE, $record->getKey()]), [
            'document' => $document->uuid,
        ])->assertRedirect();

        $this->assertTrue($record->fresh()->hasLibraryDocuments());
    }

    public function test_unlinking_leaves_the_document_in_the_library(): void
    {
        // A link is not ownership. Removing it from a record does not remove the document.
        $record = $this->record();
        $document = $this->document();
        $record->attachDocument($document);

        $this->post(route('document-management.records.detach', [DocumentRelations::EMPLOYEE, $record->getKey()]), [
            'document' => $document->uuid,
        ])->assertRedirect();

        $this->assertNotNull(Document::find($document->id));
        $this->assertFalse($record->fresh()->hasLibraryDocuments());
    }

    public function test_a_relation_the_platform_does_not_have_is_a_404_rather_than_an_attempt(): void
    {
        /*
         * The relation list is the allow-list. A request naming something not on it must not become
         * a way of nominating an arbitrary class to instantiate.
         */
        $this->post(route('document-management.records.attach', ['not_a_relation', 1]), [
            'document' => $this->document()->uuid,
        ])->assertNotFound();
    }

    public function test_a_document_the_person_cannot_see_cannot_be_linked_by_guessing_its_uuid(): void
    {
        $record = $this->record();
        $hidden = $this->document(['title' => 'Hidden', 'is_confidential' => true]);

        $this->post(route('document-management.records.attach', [DocumentRelations::EMPLOYEE, $record->getKey()]), [
            'document' => $hidden->uuid,
        ])->assertNotFound();

        $this->assertFalse($record->fresh()->hasLibraryDocuments());
    }

    // ── Coverage ─────────────────────────────────────────────────────────────

    public function test_every_registered_relation_points_at_a_model_that_can_carry_documents(): void
    {
        /*
         * A relation registered without the trait looks configured and does nothing: the picker
         * offers it, the link is written, and the record can never show what was filed against it.
         */
        $missing = [];

        foreach (DocumentRelations::all() as $relation => $definition) {
            $model = $definition['model'] ?? null;

            if ($model === null) {
                continue;
            }

            if (! in_array(HasDocuments::class, class_uses_recursive($model), true)) {
                $missing[] = $relation;
            }
        }

        $this->assertSame([], $missing, 'These relations cannot actually carry documents: ' . implode(', ', $missing));
    }

    public function test_the_phase_four_modules_are_reachable_from_the_repository(): void
    {
        // HR, Payroll, Recruitment, Procurement and Fleet. Property is absent on purpose — there is
        // no property module on this platform to point at.
        $relations = array_keys(DocumentRelations::all());

        foreach ([DocumentRelations::EMPLOYEE, DocumentRelations::PAYROLL_RUN, DocumentRelations::CANDIDATE,
            DocumentRelations::SUPPLIER, DocumentRelations::VEHICLE] as $expected) {
            $this->assertContains($expected, $relations);
        }
    }
}
