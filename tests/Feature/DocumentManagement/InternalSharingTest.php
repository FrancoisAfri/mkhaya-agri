<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use App\Modules\Operations\DocumentManagement\Policies\DocumentPolicy;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Operations\DocumentManagement\Services\InternalShareService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sharing a document with somebody inside the organisation.
 *
 * A grant is an exception, not a rule: it opens one document without opening the folder it sits in
 * and without making the recipient somebody who can read every confidential document in the company.
 * Most of these tests are about the edges of that — what a share reaches past, and what it does not.
 *
 * The listing scope and the policy are checked against each other throughout, because the day they
 * disagree is the day a document appears in a list nobody can open, or worse, the other way round.
 */
class InternalSharingTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $owner;

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

        $this->owner = $this->user('document.view', 'document.upload', 'document.share', 'document.confidential.manage');
        $this->actingAs($this->owner);
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'int+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user->fresh();
    }

    private function shares(): InternalShareService
    {
        return app(InternalShareService::class);
    }

    private function policy(): DocumentPolicy
    {
        return app(DocumentPolicy::class);
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'Employment contract', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    /** Whether this user's listing includes the document — the scope, not the policy. */
    private function listed(User $user, Document $document): bool
    {
        return Document::query()->visibleTo($user)->where('id', $document->id)->exists();
    }

    // ── The exception a share is for ─────────────────────────────────────────

    public function test_a_share_opens_one_confidential_document_without_opening_the_rest(): void
    {
        /*
         * The whole reason this exists. HR hands one employee file to a manager; the alternatives
         * are granting them the folder or making them somebody who can read every confidential
         * document in the company, and both are how a repository's permissions become meaningless.
         */
        $manager = $this->user('document.view');

        $shared = $this->document(['title' => 'Shared file', 'is_confidential' => true]);
        $other = $this->document(['title' => 'Another file', 'is_confidential' => true]);

        $this->assertFalse($this->listed($manager, $shared));

        $this->shares()->grant($shared, DocumentGrant::SUBJECT_USER, $manager->id, 'The manager');

        $this->assertTrue($this->listed($manager, $shared));
        $this->assertTrue($this->policy()->view($manager, $shared->fresh()));

        $this->assertFalse($this->listed($manager, $other), 'A share opens one document, not a class of them.');
        $this->assertFalse($this->policy()->view($manager, $other->fresh()));
    }

    public function test_a_share_reaches_into_a_folder_the_recipient_cannot_otherwise_open(): void
    {
        $manager = $this->user('document.view');

        $folder = app(FolderService::class)->create([
            'name' => 'Employee Files ' . Str::random(4),
            'is_confidential' => true,
        ]);

        $document = $this->document(['folder_id' => $folder->id]);

        $this->assertFalse($this->listed($manager, $document));

        $this->shares()->grant($document, DocumentGrant::SUBJECT_USER, $manager->id, 'The manager');

        $this->assertTrue($this->listed($manager, $document->fresh()));
    }

    // ── What a share does not reach past ─────────────────────────────────────

    public function test_a_share_does_not_hand_out_legal_hold_clearance(): void
    {
        /*
         * The one restriction a grant must not lift. A document being preserved for a matter is not
         * made readable to somebody uncleared by sharing it with them — and a share is certainly not
         * the mechanism by which that clearance should be handed out.
         */
        $manager = $this->user('document.view');

        $held = $this->document(['is_legally_sensitive' => true]);

        $this->shares()->grant($held, DocumentGrant::SUBJECT_USER, $manager->id, 'The manager');

        $this->assertFalse($this->listed($manager, $held));
        $this->assertFalse($this->policy()->view($manager, $held->fresh()));
    }

    public function test_somebody_without_the_module_permission_gains_nothing_from_a_share(): void
    {
        // A grant narrows what somebody may do with a document; it does not hand them an ability
        // the module never gave them.
        $outsider = $this->user();

        $document = $this->document();

        $this->shares()->grant($document, DocumentGrant::SUBJECT_USER, $outsider->id, 'Outsider');

        $this->assertFalse($this->policy()->view($outsider, $document->fresh()));
    }

    public function test_a_share_confers_downloading_only_where_it_said_so(): void
    {
        // Sharing a contract for review is not the same as handing over a copy of it.
        $reviewer = $this->user('document.view', 'document.download');

        $viewOnly = $this->document(['title' => 'View only', 'is_confidential' => true]);
        $withCopy = $this->document(['title' => 'With copy', 'is_confidential' => true]);

        $this->shares()->grant($viewOnly, DocumentGrant::SUBJECT_USER, $reviewer->id, 'Reviewer');
        $this->shares()->grant($withCopy, DocumentGrant::SUBJECT_USER, $reviewer->id, 'Reviewer', ['can_download' => true]);

        $this->assertTrue($this->policy()->view($reviewer, $viewOnly->fresh()));
        $this->assertFalse($this->policy()->download($reviewer, $viewOnly->fresh()));
        $this->assertTrue($this->policy()->download($reviewer, $withCopy->fresh()));
    }

    // ── Who a document can be shared with ────────────────────────────────────

    public function test_sharing_with_a_role_reaches_everybody_holding_it(): void
    {
        $role = Role::findOrCreate('doc-test-role-' . Str::random(5), 'web');

        $member = $this->user('document.view');
        $member->assignRole($role);
        $member = $member->fresh();

        $nonMember = $this->user('document.view');

        $document = $this->document(['is_confidential' => true]);

        $this->shares()->grant($document, DocumentGrant::SUBJECT_ROLE, null, $role->name);

        $this->assertTrue($this->listed($member, $document));
        $this->assertFalse($this->listed($nonMember, $document));
    }

    public function test_sharing_company_wide_reaches_anybody_the_module_already_lets_in(): void
    {
        $anyone = $this->user('document.view');
        $document = $this->document(['is_confidential' => true]);

        $this->assertFalse($this->listed($anyone, $document));

        $this->shares()->grant($document, DocumentGrant::SUBJECT_COMPANY, null, 'Everyone');

        $this->assertTrue($this->listed($anyone, $document));
    }

    // ── Housekeeping ─────────────────────────────────────────────────────────

    public function test_sharing_twice_with_the_same_person_updates_the_share_rather_than_adding_one(): void
    {
        // Otherwise the same decision restated becomes two grants that have to be revoked separately,
        // and revoking one leaves access nobody can see the source of.
        $manager = $this->user('document.view');
        $document = $this->document();

        $this->shares()->grant($document, DocumentGrant::SUBJECT_USER, $manager->id, 'The manager');
        $this->shares()->grant($document, DocumentGrant::SUBJECT_USER, $manager->id, 'The manager', ['can_download' => true]);

        $this->assertSame(1, DocumentGrant::where('document_id', $document->id)->count());
        $this->assertTrue(DocumentGrant::where('document_id', $document->id)->first()->can_download);
    }

    public function test_an_expired_share_stops_working(): void
    {
        $auditor = $this->user('document.view');
        $document = $this->document(['is_confidential' => true]);

        $grant = $this->shares()->grant($document, DocumentGrant::SUBJECT_USER, $auditor->id, 'Auditor', ['expires_days' => 7]);

        $this->assertTrue($this->listed($auditor, $document));

        $grant->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertFalse($this->listed($auditor, $document), 'A share with an end date has to actually end.');
        $this->assertFalse($this->policy()->view($auditor, $document->fresh()));
    }

    public function test_revoking_a_share_closes_the_document_again(): void
    {
        $manager = $this->user('document.view');
        $document = $this->document(['is_confidential' => true]);

        $grant = $this->shares()->grant($document, DocumentGrant::SUBJECT_USER, $manager->id, 'The manager');
        $this->assertTrue($this->listed($manager, $document));

        $this->shares()->revoke($grant);

        $this->assertFalse($this->listed($manager, $document));
    }

    public function test_sharing_and_revoking_are_written_into_the_document_history(): void
    {
        $manager = $this->user('document.view');
        $document = $this->document();

        $grant = $this->shares()->grant($document, DocumentGrant::SUBJECT_USER, $manager->id, 'The manager');
        $this->shares()->revoke($grant);

        $actions = DocumentEvent::where('document_id', $document->id)->pluck('action');

        $this->assertTrue($actions->contains(DocumentEvent::SHARED_INTERNALLY));
        $this->assertTrue($actions->contains(DocumentEvent::SHARE_INTERNAL_REVOKED));
    }

    // ── The two rules must not drift apart ───────────────────────────────────

    public function test_the_listing_and_the_policy_agree_about_every_case(): void
    {
        /*
         * The scope decides what appears in a list; the policy decides what opens. They are separate
         * code and they answer the same question, so they are checked against each other here — a
         * document listed but unopenable is confusing, and one openable but unlisted is a
         * permission that silently does nothing.
         */
        $manager = $this->user('document.view');

        $cases = [
            'plain' => $this->document(['title' => 'Plain']),
            'confidential' => $this->document(['title' => 'Confidential', 'is_confidential' => true]),
            'held' => $this->document(['title' => 'Held', 'is_legally_sensitive' => true]),
        ];

        foreach ($cases as $document) {
            $this->shares()->grant($document, DocumentGrant::SUBJECT_USER, $manager->id, 'The manager');
        }

        foreach ($cases as $label => $document) {
            $this->assertSame(
                $this->listed($manager, $document),
                $this->policy()->view($manager, $document->fresh()),
                "The scope and the policy disagree about the {$label} document.",
            );
        }
    }

    // ── Through the route ────────────────────────────────────────────────────

    public function test_the_share_label_comes_from_the_server_and_not_from_the_request(): void
    {
        /*
         * The label is what the list of shares displays afterwards. Trusting the name in the request
         * would let one person's share be labelled as somebody else's — which matters precisely
         * because this list is what somebody reads when asking who can see a document.
         */
        $this->entitle();

        $recipient = $this->user('document.view');
        $document = $this->document();

        $this->post(route('document-management.grants.store', $document->uuid), [
            'subject_type' => DocumentGrant::SUBJECT_USER,
            'subject_id' => $recipient->id,
            'subject_name' => 'Somebody Else Entirely',
        ])->assertRedirect();

        $grant = DocumentGrant::where('document_id', $document->id)->first();

        $this->assertNotNull($grant);
        $this->assertNotSame('Somebody Else Entirely', $grant->subject_name);
        $this->assertSame($recipient->email, $grant->subject_name);
    }

    public function test_sharing_needs_the_share_permission(): void
    {
        $this->entitle(withShare: false);

        $recipient = $this->user('document.view');
        $document = $this->document();

        $this->post(route('document-management.grants.store', $document->uuid), [
            'subject_type' => DocumentGrant::SUBJECT_USER,
            'subject_id' => $recipient->id,
        ])->assertForbidden();

        $this->assertSame(0, DocumentGrant::where('document_id', $document->id)->count());
    }

    /** Entitles the company to the module and acts as somebody who can use these routes. */
    private function entitle(bool $withShare = true): void
    {
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();

        $actor = $withShare
            ? $this->user('document.view', 'document.share')
            : $this->user('document.view');

        $this->actingAs($actor);
    }

    // ── The case none of the above covered ───────────────────────────────────

    public function test_somebody_nothing_restricts_sees_everything_rather_than_only_what_was_shared(): void
    {
        /*
         * The bug this exists for, and the reason every test above missed it: they all use a user
         * with something restricting their view, so the floor always had a condition in it. Give
         * somebody full clearance and no closed folders and the floor becomes an empty group, which
         * the query builder renders as nothing — leaving the OR that was meant to widen it as the
         * whole condition. A person cleared for every document in the company then saw only the
         * handful explicitly shared with them, and it read as an empty repository rather than as a
         * broken rule.
         *
         * It took opening the page in a browser to notice.
         */
        $cleared = $this->user('document.view', 'document.confidential.manage', 'document.legal_hold');

        $this->document(['title' => 'Ordinary']);
        $this->document(['title' => 'Confidential one', 'is_confidential' => true]);

        $visible = Document::query()->visibleTo($cleared)->pluck('title');

        $this->assertTrue($visible->contains('Ordinary'));
        $this->assertTrue($visible->contains('Confidential one'));
    }

    public function test_full_clearance_still_hides_what_it_should(): void
    {
        // The early return must not become a way past the one rule clearance does not cover.
        $cleared = $this->user('document.view', 'document.confidential.manage');

        $held = $this->document(['title' => 'Under hold', 'is_legally_sensitive' => true]);

        $this->assertFalse(
            Document::query()->visibleTo($cleared)->pluck('title')->contains('Under hold'),
            'Confidentiality clearance is not legal-hold clearance.',
        );
    }
}
