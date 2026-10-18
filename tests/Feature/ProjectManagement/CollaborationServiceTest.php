<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\DocumentBlob;
use App\Modules\Operations\ProjectManagement\Models\ChecklistItem;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskComment;
use App\Modules\Operations\ProjectManagement\Models\TaskWatcher;
use App\Modules\Operations\ProjectManagement\Services\CollaborationService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Checklists, comments, watchers and attachments (PM-1121).
 *
 * The behaviour worth pinning down is not that a row is written — it is who ends up subscribed, and
 * that an unwatch stays unwatched. Notification systems earn their reputation on exactly that.
 */
class CollaborationServiceTest extends ProjectManagementTestCase
{
    private Project $project;

    private Task $task;

    private CollaborationService $collab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        $this->task = app(TaskService::class)->create($this->project, ['name' => 'Erect the scaffold']);
        $this->collab = app(CollaborationService::class);
    }

    // ── Checklists ───────────────────────────────────────────────────────────

    public function test_checklist_items_are_numbered_in_the_order_they_are_added(): void
    {
        $this->collab->addChecklistItem($this->task, 'Base plates level');
        $this->collab->addChecklistItem($this->task, 'Ties at every 4 m');

        $this->assertSame(
            [1 => 'Base plates level', 2 => 'Ties at every 4 m'],
            ChecklistItem::orderBy('position')->pluck('text', 'position')->all()
        );
    }

    public function test_ticking_an_item_records_who_and_when(): void
    {
        $this->actingAs($this->manager);

        $item = $this->collab->addChecklistItem($this->task, 'Base plates level');
        $this->collab->setChecklistItemDone($item, true);

        $item->refresh();
        $this->assertTrue($item->is_done);
        $this->assertSame($this->manager->id, (int) $item->done_by);
        $this->assertNotNull($item->done_at);

        $this->collab->setChecklistItemDone($item, false);

        $item->refresh();
        $this->assertFalse($item->is_done);
        $this->assertNull($item->done_by);
    }

    /**
     * A checklist is the assignee's own working list. If ticking items moved the reported
     * percentage, progress would become a function of how finely someone chose to write their
     * notes — two people doing identical work would report differently.
     */
    public function test_ticking_a_checklist_item_does_not_move_the_tasks_progress(): void
    {
        $item = $this->collab->addChecklistItem($this->task, 'Base plates level');
        $this->collab->setChecklistItemDone($item, true);

        $this->assertSame('0.00', $this->task->fresh()->progress_pct);
    }

    public function test_a_checklist_can_be_reordered(): void
    {
        $a = $this->collab->addChecklistItem($this->task, 'A');
        $b = $this->collab->addChecklistItem($this->task, 'B');
        $c = $this->collab->addChecklistItem($this->task, 'C');

        $this->collab->reorderChecklist($this->task, [$c->uuid, $a->uuid, $b->uuid]);

        $this->assertSame(['C', 'A', 'B'], ChecklistItem::orderBy('position')->pluck('text')->all());
    }

    public function test_an_empty_checklist_item_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->collab->addChecklistItem($this->task, '   ');
    }

    // ── Comments ─────────────────────────────────────────────────────────────

    public function test_commenting_subscribes_the_author_and_anyone_named(): void
    {
        $this->actingAs($this->manager);

        $this->collab->comment($this->task, 'Ties are short, @field please check.', [$this->fieldWorker->id]);

        $this->assertEqualsCanonicalizing(
            [$this->manager->id, $this->fieldWorker->id],
            TaskWatcher::pluck('user_id')->map(fn ($id) => (int) $id)->all()
        );

        $this->assertSame('mention', TaskWatcher::where('user_id', $this->fieldWorker->id)->value('source'));
    }

    /**
     * Unwatching clears the current subscription; a *later* mention brings the person back, because
     * that is someone else deliberately calling them into the conversation. What must never happen
     * is the same comment re-adding a watch, which is why watch() is a no-op on an existing row.
     */
    public function test_a_later_mention_resubscribes_someone_who_had_unwatched(): void
    {
        $this->actingAs($this->manager);

        $this->collab->comment($this->task, 'First look @field', [$this->fieldWorker->id]);
        $this->collab->unwatch($this->task, $this->fieldWorker->id);

        $this->collab->comment($this->task, 'Any update @field?', [$this->fieldWorker->id]);

        $this->assertTrue(
            TaskWatcher::where('user_id', $this->fieldWorker->id)->exists(),
            'A later mention subscribes again — the removal only clears the current subscription.'
        );
    }

    public function test_replies_stay_one_level_deep(): void
    {
        $this->actingAs($this->manager);

        $root = $this->collab->comment($this->task, 'Ties are short.');
        $reply = $this->collab->comment($this->task, 'Ordering more.', [], $root);
        $replyToReply = $this->collab->comment($this->task, 'Thanks.', [], $reply);

        // The third attaches to the root, not to the reply: a conversation cannot grow an
        // indentation staircase nobody can read on a phone.
        $this->assertSame($root->id, $reply->parent_comment_id);
        $this->assertSame($root->id, $replyToReply->parent_comment_id);
    }

    public function test_deleting_a_comment_takes_its_replies(): void
    {
        $this->actingAs($this->manager);

        $root = $this->collab->comment($this->task, 'Ties are short.');
        $this->collab->comment($this->task, 'Ordering more.', [], $root);

        $this->collab->deleteComment($root);

        $this->assertSame(0, TaskComment::count());
    }

    public function test_editing_a_comment_marks_it_edited(): void
    {
        $this->actingAs($this->manager);

        $comment = $this->collab->comment($this->task, 'Ties are shot.');
        $this->assertNull($comment->edited_at);

        $this->collab->editComment($comment, 'Ties are short.');

        $this->assertSame('Ties are short.', $comment->fresh()->body);
        $this->assertNotNull($comment->fresh()->edited_at);
    }

    public function test_a_comment_on_another_task_cannot_be_replied_to(): void
    {
        $this->actingAs($this->manager);

        $other = app(TaskService::class)->create($this->project, ['name' => 'Strike the scaffold']);
        $comment = $this->collab->comment($other, 'Not yet.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('different task');

        $this->collab->comment($this->task, 'Reply', [], $comment);
    }

    public function test_an_oversized_comment_is_refused_with_a_useful_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attach a document instead');

        $this->collab->comment($this->task, str_repeat('a', CollaborationService::MAX_COMMENT_LENGTH + 1));
    }

    // ── Watchers ─────────────────────────────────────────────────────────────

    public function test_a_muted_watcher_is_not_notified_and_nor_is_the_person_acting(): void
    {
        $this->collab->watch($this->task, $this->manager->id);
        $this->collab->watch($this->task, $this->fieldWorker->id);
        $this->collab->watch($this->task, $this->outsider->id);
        $this->collab->setMuted($this->task, $this->outsider->id, true);

        $this->assertSame(
            [$this->fieldWorker->id],
            $this->collab->notifiableWatchers($this->task, except: $this->manager->id)
        );
    }

    public function test_watching_twice_does_not_duplicate_the_subscription(): void
    {
        $this->collab->watch($this->task, $this->manager->id, 'creator');
        $this->collab->watch($this->task, $this->manager->id, 'mention');

        $this->assertSame(1, TaskWatcher::count());
        // The first reason stands: how someone came to be watching is history, not a live value.
        $this->assertSame('creator', TaskWatcher::first()->source);
    }

    public function test_an_invented_watcher_source_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->collab->watch($this->task, $this->manager->id, 'telepathy');
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    public function test_a_file_goes_to_the_document_store_and_the_task_keeps_only_the_reference(): void
    {
        Storage::fake('local');
        $this->actingAs($this->manager);

        $attachment = $this->collab->attach(
            $this->task,
            UploadedFile::fake()->createWithContent('scaffold-cert.pdf', 'CERTIFICATE')
        );

        $this->assertSame('scaffold-cert.pdf', $attachment->filename);
        $this->assertSame($this->manager->id, (int) $attachment->uploaded_by);
        $this->assertNotEmpty($attachment->document_id);

        // In the database, under the same row-level security as every other pm_* table — and
        // nothing on a disk, which is outside that isolation entirely.
        $blob = DocumentBlob::first();
        $this->assertNotNull($blob);
        $this->assertSame('CERTIFICATE', $blob->bytes());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * Detaching removes the bytes as well as the link.
     *
     * The store now owns them, so a "remove" that only hid a row would leak storage forever and
     * leave a photograph readable after the person who uploaded it deleted it. An integrated store
     * still keeps its own — that decision belongs to whoever owns the bytes, which is why it lives
     * behind DocumentStore::delete() rather than here.
     */
    public function test_detaching_deletes_the_stored_bytes(): void
    {
        $attachment = $this->collab->attach(
            $this->task,
            UploadedFile::fake()->createWithContent('photo.jpg', 'JPEGDATA')
        );

        $this->assertSame(1, DocumentBlob::count());

        $this->collab->detach($attachment);

        $this->assertSame(0, $this->task->fresh()->attachments()->count());
        $this->assertSame(0, DocumentBlob::count());
    }

    public function test_an_oversized_file_is_refused(): void
    {
        Storage::fake('local');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than');

        $this->collab->attach($this->task, UploadedFile::fake()->create('huge.zip', 30 * 1024));
    }
}
