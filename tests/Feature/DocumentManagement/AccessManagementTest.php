<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use App\Modules\Operations\DocumentManagement\Models\DocumentGroup;
use App\Modules\Operations\DocumentManagement\Services\AccessOverview;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Operations\DocumentManagement\Services\GroupService;
use App\Modules\Operations\DocumentManagement\Services\InternalShareService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Reviewing access, rather than granting it.
 *
 * Access can now arrive from six places, each set up on its own screen. That is right for granting
 * and useless for reviewing: nobody can answer "why can this person open Employee Files" by
 * visiting six screens and holding the answer in their head.
 *
 * The two things that have to be true of a review screen. It has to agree with the enforcement —
 * a screen explaining access with its own reasoning would drift from what actually happens and be
 * believed anyway. And what it reports has to be actionable from here, or somebody reads it, goes
 * to four other screens, and the review stops happening.
 */
class AccessManagementTest extends TestCase
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

        $this->actingAs($this->reviewer());
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'am+' . Str::random(8) . '@example.test',
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

    private function reviewer(): User
    {
        return $this->user('document.view', 'document.share', 'document.folder.manage');
    }

    private function overview(): AccessOverview
    {
        return app(AccessOverview::class);
    }

    private function folder(string $name, array $attributes = []): DocumentFolder
    {
        return app(FolderService::class)->create(array_merge(['name' => $name . ' ' . Str::random(4)], $attributes));
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A document ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ], $attributes));
    }

    // ── What is going to end ─────────────────────────────────────────────────

    public function test_it_gathers_endings_from_all_four_places_one_can_be_set(): void
    {
        /*
         * The whole point. A date can be put on a document share, a folder grant, a group, or one
         * person's membership of a group, and each was set on a different screen. If this list
         * missed any of them, "temporary access" would be a promise with nothing behind it for
         * whichever kind was missed.
         */
        $person = $this->user('document.view');
        $groups = app(GroupService::class);

        $document = $this->document();
        app(InternalShareService::class)->grant(
            $document, DocumentGrant::SUBJECT_USER, $person->id, $person->email, ['expires_days' => 10],
        );

        $group = $groups->create('Auditors ' . Str::random(4), ['expires_at' => now()->addDays(20)->toDateString()]);
        $groups->addMembers($group, [$person->id], now()->addDays(5)->toDateString());
        $groups->grantFolders($group, [$this->folder('Finance')->uuid], ['view' => true], now()->addDays(15)->toDateString());

        $types = array_column($this->overview()->temporary(), 'type');

        foreach (['document_share', 'folder_grant', 'group', 'membership'] as $expected) {
            $this->assertContains($expected, $types, "A {$expected} with an end date has to appear here.");
        }
    }

    public function test_it_lists_them_soonest_first(): void
    {
        // The review this screen exists for is "what is about to lapse". Any other order makes
        // somebody sort it themselves, every time.
        $person = $this->user('document.view');

        app(InternalShareService::class)->grant(
            $this->document(), DocumentGrant::SUBJECT_USER, $person->id, $person->email, ['expires_days' => 60],
        );
        app(InternalShareService::class)->grant(
            $this->document(), DocumentGrant::SUBJECT_USER, $person->id, $person->email, ['expires_days' => 2],
        );

        $ends = array_column($this->overview()->temporary(), 'ends');
        $sorted = $ends;
        sort($sorted);

        $this->assertSame($sorted, $ends);
    }

    public function test_access_that_has_already_lapsed_is_not_listed(): void
    {
        // It is not going to end; it has ended. Listing it would put rows in front of somebody that
        // there is nothing to do about, which is how a review list stops being read.
        $person = $this->user('document.view');
        $document = $this->document();

        $grant = app(InternalShareService::class)->grant(
            $document, DocumentGrant::SUBJECT_USER, $person->id, $person->email, ['expires_days' => 5],
        );
        $grant->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame([], array_filter(
            $this->overview()->temporary(),
            fn ($row) => $row['uuid'] === $grant->uuid,
        ));
    }

    public function test_permanent_access_is_not_listed_as_ending(): void
    {
        $person = $this->user('document.view');
        app(InternalShareService::class)->grant(
            $this->document(), DocumentGrant::SUBJECT_USER, $person->id, $person->email,
        );

        $this->assertSame([], $this->overview()->temporary());
    }

    // ── Why this person can see this ─────────────────────────────────────────

    public function test_it_explains_access_using_the_thing_that_decides_it(): void
    {
        /*
         * Answered through FolderAccess rather than reimplemented. A screen with its own reasoning
         * would eventually disagree with the enforcement, and the screen is what people would
         * believe — so the folders it lists are exactly the folders the resolver opens.
         */
        $person = $this->user('document.view');
        $open = $this->folder('Policies');
        $closed = $this->folder('Payroll', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT,
            'department_unit_id' => 999_999,
        ]);

        $reachable = array_column($this->overview()->forUser($person)['folders'], 'uuid');

        $this->assertContains($open->uuid, $reachable);
        $this->assertNotContains($closed->uuid, $reachable, 'A folder they cannot open must not be listed as reachable.');
    }

    public function test_it_says_when_a_folder_is_simply_open_to_everybody(): void
    {
        /*
         * The answer people are most often surprised by, and the one no permissions screen shows,
         * because there is no row to show. "Nobody granted this — it is company-wide" is the whole
         * explanation, and without it somebody hunts for a grant that does not exist.
         */
        $person = $this->user('document.view');
        $folder = $this->folder('Company handbook');

        $row = collect($this->overview()->forUser($person)['folders'])->firstWhere('uuid', $folder->uuid);

        $this->assertSame(__('document_management.access_map.via_company'), $row['why']);
    }

    public function test_it_names_the_group_when_a_group_is_the_reason(): void
    {
        $person = $this->user('document.view');
        $groups = app(GroupService::class);

        $group = $groups->create('External auditors ' . Str::random(4));
        $groups->addMembers($group, [$person->id]);

        $folder = $this->folder('Audit', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT,
            'department_unit_id' => 999_999,
        ]);
        $groups->grantFolders($group, [$folder->uuid], ['view' => true]);

        $row = collect($this->overview()->forUser($person->fresh())['folders'])->firstWhere('uuid', $folder->uuid);

        $this->assertNotNull($row, 'The group opened it, so it has to be listed.');
        $this->assertStringContainsString($group->name, $row['why']);
    }

    public function test_it_says_where_an_inherited_grant_came_from(): void
    {
        // "You can see this because of a rule on the folder above it" is the answer that stops
        // somebody looking for a grant on the subfolder that was never there.
        $person = $this->user('document.view');
        $groups = app(GroupService::class);

        $parent = $this->folder('Human Resources', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT,
            'department_unit_id' => 999_999,
        ]);
        $child = $this->folder('Employee files', ['parent_id' => $parent->uuid]);

        $group = $groups->create('HR readers ' . Str::random(4));
        $groups->addMembers($group, [$person->id]);
        $groups->grantFolders($group, [$parent->uuid], ['view' => true]);

        $row = collect($this->overview()->forUser($person->fresh())['folders'])->firstWhere('uuid', $child->uuid);

        $this->assertNotNull($row);
        $this->assertStringContainsString($parent->name, $row['why'], 'It has to say which folder the rule is on.');
    }

    // ── Acting on it ─────────────────────────────────────────────────────────

    public function test_withdrawing_a_share_from_here_actually_withdraws_it(): void
    {
        $person = $this->user('document.view');
        $document = $this->document();

        $grant = app(InternalShareService::class)->grant(
            $document, DocumentGrant::SUBJECT_USER, $person->id, $person->email, ['expires_days' => 10],
        );

        $this->post(route('document-management.access-management.revoke'), [
            'type' => 'document_share',
            'uuid' => $grant->uuid,
        ])->assertRedirect();

        $this->assertNull(DocumentGrant::where('uuid', $grant->uuid)->first());
    }

    public function test_ending_a_group_stops_the_access_without_destroying_the_record(): void
    {
        /*
         * The group is the record of who was let in and why. Deleting it would take the answer to
         * "who had access last quarter" with it — which is exactly the question asked after
         * something turns up where it should not have.
         */
        $person = $this->user('document.view');
        $groups = app(GroupService::class);

        $group = $groups->create('Engagement ' . Str::random(4));
        $groups->addMembers($group, [$person->id]);

        $folder = $this->folder('Audit', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT,
            'department_unit_id' => 999_999,
        ]);
        $groups->grantFolders($group, [$folder->uuid], ['view' => true]);

        $this->assertTrue(app(FolderAccess::class)->can($person->fresh(), $folder, 'view'));

        $this->post(route('document-management.access-management.revoke'), [
            'type' => 'group',
            'uuid' => $group->uuid,
        ])->assertRedirect();

        $this->assertNotNull(DocumentGroup::where('uuid', $group->uuid)->first(), 'The record has to survive.');
        $this->assertFalse(
            app(FolderAccess::class)->can($person->fresh(), $folder, 'view'),
            'Ending the group has to end what it gave.',
        );
    }

    public function test_taking_one_person_out_of_a_group_leaves_the_group_alone(): void
    {
        $staying = $this->user('document.view');
        $leaving = $this->user('document.view');
        $groups = app(GroupService::class);

        $group = $groups->create('Project team ' . Str::random(4));
        $groups->addMembers($group, [$staying->id, $leaving->id], now()->addDays(10)->toDateString());

        $this->post(route('document-management.access-management.revoke'), [
            'type' => 'membership',
            'uuid' => $group->uuid . ':' . $leaving->id,
            'user_id' => $leaving->id,
        ])->assertRedirect();

        $members = DB::table('document_group_members')->where('group_id', $group->id)->pluck('user_id')->all();

        $this->assertContains($staying->id, $members);
        $this->assertNotContains($leaving->id, $members);
    }

    // ── §29 After the fact ───────────────────────────────────────────────────

    public function test_reaching_a_document_and_being_refused_is_recorded(): void
    {
        /*
         * "Who has been trying to open the disciplinary file" is a question worth being able to
         * answer, and the moment somebody arrives at a document and is turned away is the one point
         * where they demonstrably tried.
         */
        $outsider = $this->user('document.view');
        $document = $this->document(['is_confidential' => true, 'created_by' => 0]);

        $this->actingAs($outsider)
            ->get(route('document-management.documents.show', $document->uuid))
            ->assertOk();

        $this->assertTrue(
            \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::query()
                ->where('document_id', $document->id)
                ->where('action', \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::ACCESS_REFUSED)
                ->exists(),
        );
    }

    public function test_reloading_the_page_does_not_fill_the_trail(): void
    {
        /*
         * It is a GET, so a refresh, a back button or a browser prefetch would each write a row.
         * A trail full of one person reloading tells nobody anything, and buries the person who
         * tried nine times.
         */
        $outsider = $this->user('document.view');
        $document = $this->document(['is_confidential' => true, 'created_by' => 0]);

        $this->actingAs($outsider);
        $this->get(route('document-management.documents.show', $document->uuid));
        $this->get(route('document-management.documents.show', $document->uuid));
        $this->get(route('document-management.documents.show', $document->uuid));

        $this->assertSame(
            1,
            \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::query()
                ->where('document_id', $document->id)
                ->where('action', \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::ACCESS_REFUSED)
                ->count(),
        );
    }

    public function test_refusals_are_grouped_so_repetition_is_visible(): void
    {
        // One refusal is a stale link somebody was sent. Nine in a week is a conversation worth
        // having, and only the count shows the difference.
        $outsider = $this->user('document.view');
        $document = $this->document(['is_confidential' => true, 'created_by' => 0]);

        foreach (range(1, 3) as $index) {
            \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::create([
                'document_id' => $document->id,
                'action' => \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::ACCESS_REFUSED,
                'user_id' => $outsider->id,
                'created_at' => now()->subDays($index),
            ]);
        }

        $refusals = collect($this->overview()->refusals())
            ->firstWhere('document', $document->uuid);

        $this->assertNotNull($refusals);
        $this->assertSame(3, $refusals['attempts'], 'One row per person and document, with a count.');
    }

    public function test_people_holding_a_lot_of_shares_are_surfaced(): void
    {
        /*
         * Not a judgement — a prompt. Either they need them, or they accumulated them one exception
         * at a time, and nobody can tell which without being shown the number.
         */
        $person = $this->user('document.view');
        $shares = app(InternalShareService::class);

        foreach (range(1, 6) as $ignored) {
            $shares->grant($this->document(), DocumentGrant::SUBJECT_USER, $person->id, $person->email);
        }

        $row = collect($this->overview()->heavilyGranted(threshold: 5))
            ->firstWhere('user_id', $person->id);

        $this->assertNotNull($row);
        $this->assertSame(6, $row['grants']);
    }

    public function test_somebody_with_a_couple_of_shares_is_not_flagged(): void
    {
        // Flagging everybody is how somebody learns to ignore the list.
        $person = $this->user('document.view');

        app(InternalShareService::class)->grant(
            $this->document(), DocumentGrant::SUBJECT_USER, $person->id, $person->email,
        );

        $this->assertNull(
            collect($this->overview()->heavilyGranted(threshold: 5))->firstWhere('user_id', $person->id),
        );
    }

    // ── Who may look ─────────────────────────────────────────────────────────

    public function test_the_screen_is_not_open_to_everybody_who_can_read_documents(): void
    {
        /*
         * It is a map of who can reach what, which is worth having and worth withholding: it names
         * every folder in the company and everybody who can open them. Reading documents is not
         * grounds for reading that.
         */
        $this->actingAs($this->user('document.view'))
            ->get(route('document-management.access-management'))
            ->assertForbidden();
    }

    public function test_somebody_who_answers_access_requests_may_review_them(): void
    {
        $this->get(route('document-management.access-management'))->assertOk();
    }

    public function test_withdrawing_needs_the_same_right_as_granting(): void
    {
        $person = $this->user('document.view');
        $grant = app(InternalShareService::class)->grant(
            $this->document(), DocumentGrant::SUBJECT_USER, $person->id, $person->email, ['expires_days' => 5],
        );

        $this->actingAs($this->user('document.view'))
            ->post(route('document-management.access-management.revoke'), [
                'type' => 'document_share',
                'uuid' => $grant->uuid,
            ])
            ->assertForbidden();

        $this->assertNotNull(DocumentGrant::where('uuid', $grant->uuid)->first());
    }
}
