<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentComment;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Notifications\DocumentMentionNotification;
use App\Modules\Operations\DocumentManagement\Services\CommentService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The conversation about a document, kept next to the document.
 *
 * It happens in email today, and that is the half of the record that never arrives: the contract is
 * filed and the reason it says what it says is in somebody's inbox. Six months later the clause is
 * queried and nobody can find the exchange that produced it.
 */
class DocumentCommentsTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'cmt+' . Str::random(8) . '@example.test',
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

    private function reader(): User
    {
        return $this->user('document.view');
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A contract ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ], $attributes));
    }

    private function comments(): CommentService
    {
        return app(CommentService::class);
    }

    // ── Saying something ─────────────────────────────────────────────────────

    public function test_somebody_who_can_read_a_document_can_comment_on_it(): void
    {
        // No permission of its own, deliberately: requiring a separate right to speak means the
        // people who notice the problem are the ones who cannot report it.
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader)
            ->post(route('document-management.documents.comments.store', $document->uuid), [
                'body' => 'Clause 4 contradicts the schedule.',
            ])
            ->assertRedirect();

        $this->assertSame(1, DocumentComment::where('document_id', $document->id)->count());
    }

    public function test_somebody_who_cannot_read_it_cannot_comment_on_it(): void
    {
        $outsider = $this->user('document.view');
        $document = $this->document(['is_confidential' => true, 'created_by' => 0]);

        $this->actingAs($outsider)
            ->post(route('document-management.documents.comments.store', $document->uuid), ['body' => 'Hello'])
            ->assertForbidden();
    }

    public function test_a_comment_is_recorded_on_the_documents_timeline(): void
    {
        // "When was this queried" is asked about the document, not about the comment thread.
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader);
        $this->comments()->add($document, 'Please check the dates.');

        $this->assertTrue(
            DocumentEvent::where('document_id', $document->id)->where('action', DocumentEvent::COMMENTED)->exists(),
        );
    }

    // ── Threads ──────────────────────────────────────────────────────────────

    public function test_a_reply_belongs_to_the_thread_and_not_to_another_reply(): void
    {
        /*
         * One level, not a tree. A thread in a side panel is read top to bottom, and arbitrary
         * nesting turns a disagreement about a clause into a shape nobody can follow and an indent
         * with no visible end.
         */
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader);
        $thread = $this->comments()->add($document, 'Clause 4 is wrong.');
        $reply = $this->comments()->add($document, 'Agreed.', $thread);
        $deeper = $this->comments()->add($document, 'So is clause 5.', $reply);

        $this->assertSame($thread->id, $reply->parent_id);
        $this->assertSame($thread->id, $deeper->parent_id, 'A reply to a reply joins the same thread.');
    }

    public function test_threads_are_listed_with_the_unsettled_ones_first(): void
    {
        // The reason somebody opens the panel is to see what is still outstanding. A settled thread
        // from this morning is not it.
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader);
        $settled = $this->comments()->add($document, 'Already dealt with.');
        $this->comments()->resolve($settled, $reader);
        $this->comments()->add($document, 'Still outstanding.');

        $threads = $this->comments()->threadsFor($document);

        $this->assertFalse($threads[0]['resolved']);
        $this->assertTrue($threads[1]['resolved']);
    }

    public function test_a_reply_cannot_be_attached_across_documents(): void
    {
        /*
         * The uuid is the only thing needed to post a reply, so without this check a uuid from
         * another document would attach a reply to a thread its author cannot see — and the reply
         * would then be readable by everybody who can read that other document.
         */
        $reader = $this->reader();
        $mine = $this->document();
        $theirs = $this->document();

        $this->actingAs($reader);
        $elsewhere = $this->comments()->add($theirs, 'On a different document.');

        $this->post(route('document-management.documents.comments.store', $mine->uuid), [
            'body' => 'Sneaking in.',
            'parent' => $elsewhere->uuid,
        ])->assertRedirect();

        $posted = DocumentComment::where('document_id', $mine->id)->first();

        $this->assertNotNull($posted);
        $this->assertNull($posted->parent_id, 'It has to land as its own thread, not on the other document.');
    }

    // ── Settling ─────────────────────────────────────────────────────────────

    public function test_settling_a_thread_settles_its_replies_too(): void
    {
        // A reply left open under a settled opener keeps the thread showing as live, which is the
        // state settling exists to clear.
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader);
        $thread = $this->comments()->add($document, 'Query.');
        $reply = $this->comments()->add($document, 'Answer.', $thread);

        $this->comments()->resolve($thread, $reader);

        $this->assertNotNull($reply->fresh()->resolved_at);
    }

    public function test_settling_a_reply_settles_the_whole_thread(): void
    {
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader);
        $thread = $this->comments()->add($document, 'Query.');
        $reply = $this->comments()->add($document, 'Answer.', $thread);

        $this->comments()->resolve($reply, $reader);

        $this->assertNotNull($thread->fresh()->resolved_at);
    }

    public function test_a_settled_thread_is_kept_rather_than_removed(): void
    {
        /*
         * "Why does clause 4 say this" is asked long after everybody involved has moved on, and a
         * thread deleted on settling takes the answer with it.
         */
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader);
        $thread = $this->comments()->add($document, 'Because of the 2019 amendment.');
        $this->comments()->resolve($thread, $reader);

        $this->assertNotNull(DocumentComment::where('uuid', $thread->uuid)->first());
    }

    public function test_a_thread_settled_too_early_can_be_reopened(): void
    {
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader);
        $thread = $this->comments()->add($document, 'Query.');
        $this->comments()->resolve($thread, $reader);

        $this->post(route('document-management.documents.comments.resolve', [$document->uuid, $thread->uuid]), [
            'reopen' => true,
        ])->assertRedirect();

        $this->assertNull($thread->fresh()->resolved_at);
    }

    public function test_the_open_count_ignores_settled_threads_and_replies(): void
    {
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader);
        $first = $this->comments()->add($document, 'One.');
        $this->comments()->add($document, 'A reply.', $first);
        $this->comments()->add($document, 'Two.');
        $this->comments()->resolve($first, $reader);

        $this->assertSame(1, $this->comments()->openCount($document), 'One thread, not one thread plus a reply.');
    }

    // ── Mentions ─────────────────────────────────────────────────────────────

    public function test_naming_somebody_actually_reaches_them(): void
    {
        /*
         * The reason mentions are worth building. A mention that only renders in bold is a mention
         * nobody sees, and the person who wrote it believes they have asked a question and is
         * waiting for an answer that is never coming.
         */
        Notification::fake();

        $author = $this->reader();
        $colleague = $this->reader();
        $document = $this->document();

        $this->actingAs($author);
        $this->comments()->add($document, 'Could you check this?', mentions: [$colleague->id]);

        Notification::assertSentTo($colleague, DocumentMentionNotification::class);
    }

    public function test_naming_yourself_does_not_notify_you(): void
    {
        // Noise is how somebody learns to ignore the notifications that matter.
        Notification::fake();

        $author = $this->reader();
        $document = $this->document();

        $this->actingAs($author);
        $this->comments()->add($document, 'Note to self.', mentions: [$author->id]);

        Notification::assertNothingSent();
    }

    public function test_the_notification_carries_the_title_and_not_the_document(): void
    {
        /*
         * Naming a colleague on a confidential document must not be a way of telling them what it
         * says. The link lands them wherever the permissions put them, which may well be the page
         * offering to request access.
         */
        $author = $this->reader();
        $document = $this->document(['title' => 'Board pack']);

        $this->actingAs($author);
        $comment = $this->comments()->add($document, 'A remark about something sensitive.');

        $payload = (new DocumentMentionNotification($document, $comment))->toArray($author);

        $this->assertSame('Board pack', $payload['title']);
        $this->assertArrayNotHasKey('body', $payload, 'The document itself does not travel in a notification.');
    }

    // ── Removing ─────────────────────────────────────────────────────────────

    public function test_only_the_author_can_remove_their_own_comment(): void
    {
        // A comment somebody else can delete is a record nobody can rely on, and the point of
        // keeping the conversation here is that it stays.
        $author = $this->reader();
        $other = $this->reader();
        $document = $this->document();

        $this->actingAs($author);
        $comment = $this->comments()->add($document, 'Mine.');

        $this->actingAs($other)
            ->delete(route('document-management.documents.comments.destroy', [$document->uuid, $comment->uuid]))
            ->assertForbidden();

        $this->assertNotNull($comment->fresh());
    }

    public function test_removing_a_thread_takes_its_replies_with_it(): void
    {
        // A reply to a remark that is gone reads as an answer to nothing.
        $author = $this->reader();
        $document = $this->document();

        $this->actingAs($author);
        $thread = $this->comments()->add($document, 'Query.');
        $reply = $this->comments()->add($document, 'Answer.', $thread);

        $this->delete(route('document-management.documents.comments.destroy', [$document->uuid, $thread->uuid]))
            ->assertRedirect();

        $this->assertNull($reply->fresh());
    }
}
