<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklist;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklistTemplate;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklistTemplateItem;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentRequest;
use App\Modules\Operations\DocumentManagement\Services\ChecklistService;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What should be on file, and what is missing.
 *
 * The repository can say what it holds. It cannot say what it is missing, and missing is the thing
 * people are actually asked about. That gap is filled today by a spreadsheet beside the repository,
 * which is a second record of the same facts and disagrees with the first within a week.
 *
 * Which is why the tick has to happen by itself. A checklist somebody maintains by hand is the
 * spreadsheet again, with extra steps.
 */
class DocumentChecklistsTest extends TestCase
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

        $this->actingAs($this->manager());
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'chk+' . Str::random(8) . '@example.test',
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

    private function manager(): User
    {
        return $this->user('document.view', 'document.upload', 'document.folder.manage');
    }

    private function category(string $name): DocumentCategory
    {
        return DocumentCategory::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name . ' ' . Str::random(4),
        ]);
    }

    private function folder(string $name = 'Employee files'): DocumentFolder
    {
        return app(FolderService::class)->create(['name' => $name . ' ' . Str::random(4)]);
    }

    private function document(DocumentFolder $folder, ?DocumentCategory $category = null): Document
    {
        return Document::create([
            'title' => 'A document ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
            'folder_id' => $folder->id,
            'category_id' => $category?->id,
        ]);
    }

    /** @param list<array{0: string, 1: ?DocumentCategory, 2?: bool}> $items */
    private function template(array $items, string $name = 'New employee pack'): DocumentChecklistTemplate
    {
        $template = DocumentChecklistTemplate::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name . ' ' . Str::random(4),
        ]);

        foreach ($items as $index => [$title, $category]) {
            DocumentChecklistTemplateItem::create([
                'template_id' => $template->id,
                'title' => $title,
                'category_id' => $category?->id,
                'is_required' => $items[$index][2] ?? true,
                'sort_order' => $index,
            ]);
        }

        return $template->fresh();
    }

    /** Somebody the people picker will actually offer: a live account with a live person behind it. */
    private function staff(string $surname): User
    {
        $user = $this->user('document.view', 'document.upload');

        DB::table('hr_people')->insert([
            'user_id' => $user->id,
            'company_id' => $this->companyId,
            'first_name' => 'Test',
            'surname' => $surname . ' ' . Str::random(4),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function service(): ChecklistService
    {
        return app(ChecklistService::class);
    }

    // ── The rule and the case ────────────────────────────────────────────────

    public function test_starting_a_pack_copies_the_items_rather_than_pointing_at_them(): void
    {
        /*
         * What was required of somebody in March is part of the record of March. If the items were
         * read through the template, a rule changed in November would silently rewrite every pack
         * assembled before it.
         */
        $idCopy = $this->category('ID copy');
        $template = $this->template([['Certified ID copy', $idCopy]]);

        $checklist = $this->service()->start($template, ['subject' => 'Thandi']);

        DocumentChecklistTemplateItem::where('template_id', $template->id)
            ->update(['title' => 'Something else entirely']);

        $this->assertSame('Certified ID copy', $checklist->fresh()->items->first()->title);
    }

    public function test_a_pack_started_against_a_folder_reads_what_is_already_there(): void
    {
        /*
         * Opening a new pack showing everything as missing, when half of it is already filed, sends
         * somebody off to re-upload documents that are already in the repository.
         */
        $idCopy = $this->category('ID copy');
        $folder = $this->folder();
        $this->document($folder, $idCopy);

        $checklist = $this->service()->start(
            $this->template([['Certified ID copy', $idCopy]]),
            ['folder_id' => $folder->id],
        );

        $this->assertTrue($checklist->items->first()->isSatisfied(), 'It was already on file.');
    }

    // ── Ticking itself ───────────────────────────────────────────────────────

    public function test_filing_a_matching_document_ticks_the_item(): void
    {
        // The whole point. A checklist somebody has to tick by hand is a second record of the same
        // fact, and the two disagree within a week.
        $idCopy = $this->category('ID copy');
        $folder = $this->folder();

        $checklist = $this->service()->start(
            $this->template([['Certified ID copy', $idCopy]]),
            ['folder_id' => $folder->id],
        );

        $this->assertFalse($checklist->items->first()->isSatisfied());

        $this->document($folder, $idCopy);
        $this->service()->reconcile($checklist->fresh());

        $this->assertTrue($checklist->fresh()->items->first()->isSatisfied());
    }

    public function test_one_document_cannot_tick_two_items(): void
    {
        /*
         * Two items both asking for a certificate would otherwise both be ticked by the same file,
         * and the pack would report itself complete while one of the two was never provided.
         */
        $certificate = $this->category('Certificate');
        $folder = $this->folder();

        $checklist = $this->service()->start(
            $this->template([['First certificate', $certificate], ['Second certificate', $certificate]]),
            ['folder_id' => $folder->id],
        );

        $this->document($folder, $certificate);
        $this->service()->reconcile($checklist->fresh());

        $satisfied = $checklist->fresh()->items->filter(fn ($item) => $item->isSatisfied());

        $this->assertCount(1, $satisfied, 'One document answers one item.');
    }

    public function test_a_document_of_the_wrong_kind_does_not_tick_anything(): void
    {
        $idCopy = $this->category('ID copy');
        $payslip = $this->category('Payslip');
        $folder = $this->folder();

        $checklist = $this->service()->start(
            $this->template([['Certified ID copy', $idCopy]]),
            ['folder_id' => $folder->id],
        );

        $this->document($folder, $payslip);
        $this->service()->reconcile($checklist->fresh());

        $this->assertFalse($checklist->fresh()->items->first()->isSatisfied());
    }

    public function test_an_item_with_no_category_is_never_ticked_automatically(): void
    {
        // It can only be ticked by hand, and the screen says so rather than leaving somebody
        // waiting for something that will not happen.
        $folder = $this->folder();

        $checklist = $this->service()->start(
            $this->template([['Anything at all', null]]),
            ['folder_id' => $folder->id],
        );

        $this->document($folder, $this->category('Whatever'));
        $this->service()->reconcile($checklist->fresh());

        $this->assertFalse($checklist->fresh()->items->first()->isSatisfied());
    }

    public function test_a_document_that_disappears_unticks_the_item(): void
    {
        /*
         * The direction that matters most. A pack that looks complete when it is not is worse than
         * no pack at all, because somebody relies on it.
         */
        $idCopy = $this->category('ID copy');
        $folder = $this->folder();
        $document = $this->document($folder, $idCopy);

        $checklist = $this->service()->start(
            $this->template([['Certified ID copy', $idCopy]]),
            ['folder_id' => $folder->id],
        );

        $this->assertTrue($checklist->items->first()->isSatisfied());

        $document->delete();
        $this->service()->reconcile($checklist->fresh());

        $this->assertFalse($checklist->fresh()->items->first()->isSatisfied(), 'The document is gone, so the item is not met.');
        $this->assertNull($checklist->fresh()->completed_at);
    }

    public function test_a_document_chosen_by_hand_outranks_the_category_rule(): void
    {
        // Somebody deciding "this is the one" beats a rule about kinds of document.
        $idCopy = $this->category('ID copy');
        $folder = $this->folder();
        $oddOne = $this->document($folder, $this->category('Something else'));

        $checklist = $this->service()->start(
            $this->template([['Certified ID copy', $idCopy]]),
            ['folder_id' => $folder->id],
        );

        $item = $checklist->items->first();
        $this->service()->satisfyWith($item, $oddOne);
        $this->service()->reconcile($checklist->fresh());

        $this->assertSame($oddOne->id, (int) $item->fresh()->document_id);
    }

    // ── Completeness ─────────────────────────────────────────────────────────

    public function test_optional_items_do_not_hold_a_pack_open(): void
    {
        /*
         * "Proof of qualification, where the role requires it" belongs on the list — leaving it off
         * means it is forgotten in the cases that do need it. But treating it as required would
         * leave every pack permanently unfinished, and a bar nobody can fill is one nobody reads.
         */
        $idCopy = $this->category('ID copy');
        $extra = $this->category('Qualification');
        $folder = $this->folder();

        $checklist = $this->service()->start(
            $this->template([
                ['Certified ID copy', $idCopy, true],
                ['Proof of qualification', $extra, false],
            ]),
            ['folder_id' => $folder->id],
        );

        $this->document($folder, $idCopy);
        $this->service()->reconcile($checklist->fresh());

        $checklist = $checklist->fresh();

        $this->assertTrue($checklist->isComplete());
        $this->assertSame(100, $this->service()->progress($checklist)['percent']);
    }

    public function test_the_date_a_pack_became_complete_is_kept(): void
    {
        /*
         * A real question — it is often the date somebody started, or the date a supplier was
         * approved. Restamping it every time the page is opened would lose it.
         */
        $idCopy = $this->category('ID copy');
        $folder = $this->folder();
        $this->document($folder, $idCopy);

        $checklist = $this->service()->start(
            $this->template([['Certified ID copy', $idCopy]]),
            ['folder_id' => $folder->id],
        );

        $first = $checklist->fresh()->completed_at;
        $this->assertNotNull($first);

        $this->service()->reconcile($checklist->fresh());

        $this->assertEquals($first, $checklist->fresh()->completed_at);
    }

    public function test_progress_is_measured_against_what_is_required(): void
    {
        $idCopy = $this->category('ID copy');
        $payslip = $this->category('Payslip');
        $folder = $this->folder();

        $checklist = $this->service()->start(
            $this->template([['ID copy', $idCopy], ['Payslip', $payslip]]),
            ['folder_id' => $folder->id],
        );

        $this->document($folder, $idCopy);
        $this->service()->reconcile($checklist->fresh());

        $progress = $this->service()->progress($checklist->fresh());

        $this->assertSame(2, $progress['required']);
        $this->assertSame(1, $progress['satisfied']);
        $this->assertSame(1, $progress['outstanding']);
        $this->assertSame(50, $progress['percent']);
    }

    // ── Chasing what is missing ──────────────────────────────────────────────

    public function test_everything_outstanding_becomes_a_request_in_one_act(): void
    {
        /*
         * The reason checklists and the request queue belong in the same module. Asking for six
         * missing documents one at a time is where the sixth gets forgotten — which is the failure
         * this whole feature exists to prevent.
         */
        $folder = $this->folder();
        $checklist = $this->service()->start(
            $this->template([
                ['Certified ID copy', $this->category('ID copy')],
                ['Proof of address', $this->category('Address')],
                ['Bank confirmation', $this->category('Bank')],
            ]),
            ['folder_id' => $folder->id],
        );

        $person = $this->user('document.view');

        $this->post(route('document-management.checklists.chase', $checklist->uuid), [
            'people' => [$person->id],
        ])->assertRedirect();

        $this->assertSame(3, DocumentRequest::where('requested_from', $person->id)->count());
    }

    public function test_a_chased_request_carries_the_filing_from_the_checklist(): void
    {
        // The person answering should not have to know where it goes.
        $idCopy = $this->category('ID copy');
        $folder = $this->folder();
        $checklist = $this->service()->start(
            $this->template([['Certified ID copy', $idCopy]]),
            ['folder_id' => $folder->id],
        );

        $person = $this->user('document.view');

        $this->post(route('document-management.checklists.chase', $checklist->uuid), [
            'people' => [$person->id],
        ])->assertRedirect();

        $request = DocumentRequest::where('requested_from', $person->id)->first();

        $this->assertSame($folder->id, (int) $request->folder_id);
        $this->assertSame($idCopy->id, (int) $request->category_id);
    }

    public function test_what_is_already_on_file_is_not_asked_for(): void
    {
        $idCopy = $this->category('ID copy');
        $payslip = $this->category('Payslip');
        $folder = $this->folder();
        $this->document($folder, $idCopy);

        $checklist = $this->service()->start(
            $this->template([['ID copy', $idCopy], ['Payslip', $payslip]]),
            ['folder_id' => $folder->id],
        );

        $person = $this->user('document.view');

        $this->post(route('document-management.checklists.chase', $checklist->uuid), [
            'people' => [$person->id],
        ])->assertRedirect();

        $asked = DocumentRequest::where('requested_from', $person->id)->pluck('title')->all();

        $this->assertSame(['Payslip'], $asked, 'Only what is actually missing.');
    }

    // ── Starting packs for people ────────────────────────────────────────────

    public function test_picking_several_people_starts_a_pack_each(): void
    {
        /*
         * Onboarding three people in a week is one act, not three visits to this screen — and a
         * pack per person is the only shape that works, because each person's is complete or not
         * on its own.
         */
        $template = $this->template([['ID copy', $this->category('ID copy')]]);
        $people = [$this->staff('Nkosi'), $this->staff('Dlamini'), $this->staff('Mbeki')];

        $this->post(route('document-management.checklists.store'), [
            'template' => $template->uuid,
            'people' => array_map(fn ($u) => $u->id, $people),
        ])->assertRedirect();

        $this->assertSame(3, DocumentChecklist::where('template_id', $template->id)->count());
    }

    public function test_a_pack_is_named_for_the_person_it_is_about(): void
    {
        // The subject is what somebody searches for months later. A blank one is a pack that is
        // never found again.
        $template = $this->template([['ID copy', $this->category('ID copy')]]);
        $person = $this->staff('Nkosi');

        $this->post(route('document-management.checklists.store'), [
            'template' => $template->uuid,
            'people' => [$person->id],
        ])->assertRedirect();

        $this->assertNotNull(DocumentChecklist::where('subject', 'like', '%Nkosi%')->first());
    }

    public function test_a_pack_can_still_be_about_a_supplier_or_a_tender(): void
    {
        // Not everything a pack is assembled for is a person on the staff list, and removing the
        // free text would have removed the supplier and tender cases with it.
        $template = $this->template([['Tax clearance', $this->category('Tax')]]);

        $this->post(route('document-management.checklists.store'), [
            'template' => $template->uuid,
            'subject' => 'Acme Holdings',
        ])->assertRedirect();

        $this->assertNotNull(DocumentChecklist::where('subject', 'Acme Holdings')->first());
    }

    public function test_a_pack_about_nobody_is_refused(): void
    {
        $template = $this->template([['ID copy', $this->category('ID copy')]]);

        $this->post(route('document-management.checklists.store'), ['template' => $template->uuid])
            ->assertSessionHas('error');

        $this->assertSame(0, DocumentChecklist::where('template_id', $template->id)->count());
    }

    public function test_a_pack_cannot_be_started_against_somebody_in_another_company(): void
    {
        /*
         * The ids come from a form. Resolving them through the same picker that offered them means
         * a posted id from elsewhere is not a pick, it is a probe.
         */
        $template = $this->template([['ID copy', $this->category('ID copy')]]);

        $outsider = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'out+' . Str::random(6) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId + 9999,
        ]);

        $this->post(route('document-management.checklists.store'), [
            'template' => $template->uuid,
            'people' => [$outsider->id],
        ])->assertSessionHas('error');

        $this->assertSame(0, DocumentChecklist::where('template_id', $template->id)->count());
    }

    // ── Who may do what ──────────────────────────────────────────────────────

    public function test_the_screen_opens_for_anybody_who_can_use_the_module(): void
    {
        $this->actingAs($this->user('document.view'))
            ->get(route('document-management.checklists'))
            ->assertOk();
    }

    public function test_writing_the_rule_needs_more_than_reading(): void
    {
        // A pack type is a statement about how the organisation files things, not a personal note.
        $this->actingAs($this->user('document.view'))
            ->post(route('document-management.checklists.templates.store'), [
                'name' => 'Sneaky pack',
                'items' => [['title' => 'Something']],
            ])
            ->assertForbidden();
    }

    public function test_a_pack_with_no_folder_ticks_nothing_and_does_not_fail(): void
    {
        // Perfectly legitimate — somebody sketching a pack before deciding where it will live. It
        // must not throw, and it must not claim to be complete.
        $checklist = $this->service()->start($this->template([['ID copy', $this->category('ID copy')]]));

        $this->assertSame(0, $this->service()->reconcile($checklist));
        $this->assertFalse($checklist->fresh()->isComplete());
    }
}
