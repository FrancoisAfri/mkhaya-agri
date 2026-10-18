<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ChecklistItem;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAttachment;
use App\Modules\Operations\ProjectManagement\Models\TaskComment;
use App\Modules\Operations\ProjectManagement\Models\TaskRecurrence;
use App\Modules\Operations\ProjectManagement\Services\CollaborationService;
use App\Modules\Operations\ProjectManagement\Services\RecurrenceService;
use App\Modules\Operations\ProjectManagement\Support\Recurrence\Rrule;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything inside the task drawer: checklists, comments, watchers, files and the recurrence rule
 * (PM-1121, PM-1122).
 *
 * Each action redirects back and the drawer reloads its own prop, so the page never holds a stale
 * copy of a conversation two people are having at once.
 *
 * Taking part is gated on `collaborate` — the same bar as seeing the task. Changing the shape of
 * the work (adding checklist items, removing someone else's comment, setting a recurrence) needs
 * `update`, because those outlast the person who did them.
 */
class TaskCollaborationController extends Controller
{
    public function __construct(
        private readonly CollaborationService $collab,
        private readonly RecurrenceService $recurrences,
    ) {
    }

    // ── Checklist ────────────────────────────────────────────────────────────

    public function storeChecklistItem(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $task = $this->task($project, $uuid);
        $this->authorize('update', $task);

        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);

        $this->collab->addChecklistItem($task, $data['text']);

        return back()->with('success', __('project_management.flash.checklist_added'));
    }

    /** Ticking is participation; retitling is an edit. */
    public function updateChecklistItem(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $item = ChecklistItem::where('uuid', $uuid)->firstOrFail();
        $task = $this->task($project, $item->task->uuid);

        $data = $request->validate([
            'is_done' => ['nullable', 'boolean'],
            'text'    => ['nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('text', $data) && $data['text'] !== null) {
            $this->authorize('update', $task);
            $item->forceFill(['text' => $data['text']])->save();
        }

        if (array_key_exists('is_done', $data) && $data['is_done'] !== null) {
            $this->authorize('collaborate', $task);
            $this->collab->setChecklistItemDone($item, (bool) $data['is_done']);
        }

        return back();
    }

    public function reorderChecklist(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $task = $this->task($project, $uuid);
        $this->authorize('update', $task);

        $data = $request->validate([
            'uuids'   => ['required', 'array', 'max:200'],
            'uuids.*' => ['uuid'],
        ]);

        $this->collab->reorderChecklist($task, $data['uuids']);

        return back();
    }

    public function destroyChecklistItem(Project $project, string $uuid): RedirectResponse
    {
        $item = ChecklistItem::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $this->task($project, $item->task->uuid));

        $this->collab->deleteChecklistItem($item);

        return back();
    }

    // ── Comments ─────────────────────────────────────────────────────────────

    public function storeComment(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $task = $this->task($project, $uuid);
        $this->authorize('collaborate', $task);

        $data = $request->validate([
            'body'     => ['required', 'string', 'max:' . CollaborationService::MAX_COMMENT_LENGTH],
            'mentions' => ['nullable', 'array', 'max:50'],
            // A validation rule queries through the query builder, so neither CompanyScope nor
            // Eloquent's soft-delete applies — RLS would catch it in production but not on a
            // self-hosted database without the session variable set. Scoped explicitly instead, so
            // an id pasted into the payload cannot subscribe someone from another tenant.
            'mentions.*' => [
                Rule::exists('users', 'id')
                    ->whereNull('deleted_at')
                    ->when($companyId = app(CurrentCompany::class)->id(), fn ($rule) => $rule->where('company_id', $companyId)),
            ],
            'reply_to' => ['nullable', 'uuid'],
        ]);

        $replyTo = ! empty($data['reply_to'])
            ? TaskComment::where('task_id', $task->id)->where('uuid', $data['reply_to'])->firstOrFail()
            : null;

        $this->collab->comment($task, $data['body'], $data['mentions'] ?? [], $replyTo);

        return back()->with('success', __('project_management.flash.comment_added'));
    }

    public function updateComment(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $comment = TaskComment::where('uuid', $uuid)->firstOrFail();
        $task = $this->task($project, $comment->task->uuid);

        // Only the author edits their own words. Someone else rewriting them and leaving the byline
        // in place would be putting words in their mouth.
        abort_unless((int) $comment->author_id === Auth::id(), 403);
        $this->authorize('collaborate', $task);

        $data = $request->validate(['body' => ['required', 'string', 'max:' . CollaborationService::MAX_COMMENT_LENGTH]]);

        $this->collab->editComment($comment, $data['body']);

        return back();
    }

    public function destroyComment(Project $project, string $uuid): RedirectResponse
    {
        $comment = TaskComment::where('uuid', $uuid)->firstOrFail();
        $task = $this->task($project, $comment->task->uuid);

        // Your own, or you are the one who runs the work.
        if ((int) $comment->author_id !== Auth::id()) {
            $this->authorize('update', $task);
        } else {
            $this->authorize('collaborate', $task);
        }

        $this->collab->deleteComment($comment);

        return back();
    }

    // ── Watchers ─────────────────────────────────────────────────────────────

    public function watch(Project $project, string $uuid): RedirectResponse
    {
        $task = $this->task($project, $uuid);
        $this->authorize('collaborate', $task);

        $this->collab->watch($task, (int) Auth::id());

        return back();
    }

    public function unwatch(Project $project, string $uuid): RedirectResponse
    {
        $task = $this->task($project, $uuid);
        $this->authorize('collaborate', $task);

        $this->collab->unwatch($task, (int) Auth::id());

        return back();
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    public function storeAttachment(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $task = $this->task($project, $uuid);
        $this->authorize('collaborate', $task);

        $request->validate([
            'file' => ['required', 'file', 'max:' . (CollaborationService::MAX_ATTACHMENT_BYTES / 1024)],
        ]);

        $this->collab->attach($task, $request->file('file'));

        return back()->with('success', __('project_management.flash.attachment_added'));
    }

    /**
     * Streamed from the store when it holds the bytes, which is PM's own database-backed store —
     * so every download passes this policy check and there is no URL that would serve the file
     * without one. Stores that keep their bytes elsewhere hand back a short-lived link instead.
     */
    public function downloadAttachment(Project $project, string $uuid): RedirectResponse|StreamedResponse
    {
        $attachment = TaskAttachment::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $this->task($project, $attachment->task->uuid));

        if ($contents = $this->collab->attachmentContents($attachment)) {
            return response()->streamDownload(
                fn () => print $contents->bytes,
                $contents->filename,
                $contents->headers(),
                'inline',
            );
        }

        $url = $this->collab->attachmentUrl($attachment);

        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    public function destroyAttachment(Project $project, string $uuid): RedirectResponse
    {
        $attachment = TaskAttachment::where('uuid', $uuid)->firstOrFail();
        $task = $this->task($project, $attachment->task->uuid);

        if ((int) $attachment->uploaded_by !== Auth::id()) {
            $this->authorize('update', $task);
        } else {
            $this->authorize('collaborate', $task);
        }

        $this->collab->detach($attachment);

        return back();
    }

    // ── Recurrence ───────────────────────────────────────────────────────────

    public function storeRecurrence(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $task = $this->task($project, $uuid);
        $this->authorize('create', Task::class);
        $this->authorize('update', $task);

        $data = $request->validate([
            'freq'         => ['required', Rule::in(Rrule::FREQUENCIES)],
            'interval'     => ['nullable', 'integer', 'min:1', 'max:52'],
            'byday'        => ['nullable', 'array', 'max:7'],
            'byday.*'      => [Rule::in(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'])],
            'bymonthday'   => ['nullable', 'integer', 'between:-31,31'],
            'count'        => ['nullable', 'integer', 'min:1', 'max:500'],
            'starts_at'    => ['required', 'date'],
            'ends_at'      => ['nullable', 'date', 'after:starts_at'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:' . TaskRecurrence::MAX_HORIZON_DAYS],
        ]);

        try {
            $recurrence = $this->recurrences->create($project, $task, $this->buildRrule($data), [
                'starts_at'    => $data['starts_at'],
                'ends_at'      => $data['ends_at'] ?? null,
                'horizon_days' => $data['horizon_days'] ?? 60,
            ]);

            // Generate straight away, so the person who set the rule sees it work rather than
            // wondering until 02:30 tomorrow whether it took.
            $this->recurrences->generate($recurrence);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['freq' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.recurrence_created'));
    }

    public function updateRecurrence(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $recurrence = TaskRecurrence::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $recurrence->template);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $data['is_active']
            ? $this->recurrences->resume($recurrence)
            : $this->recurrences->pause($recurrence);

        return back();
    }

    public function destroyRecurrence(Project $project, string $uuid): RedirectResponse
    {
        $recurrence = TaskRecurrence::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $recurrence->template);

        $cancelled = $this->recurrences->stop($recurrence);

        return back()->with('success', __('project_management.flash.recurrence_stopped', ['count' => $cancelled]));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Scoped to the project in the URL, so a task uuid from another job is a 404, not a 403. */
    private function task(Project $project, string $uuid): Task
    {
        return Task::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Assemble an RRULE from the form's separate fields.
     *
     * The form asks in the shape people think — "every 2 weeks, on Monday and Wednesday" — and this
     * turns that into the standard string, which is what gets stored. Anyone who prefers to type
     * `FREQ=MONTHLY;BYDAY=-1FR` still can through the API; the parser is the same either way.
     *
     * @param  array<string,mixed>  $data
     */
    private function buildRrule(array $data): string
    {
        $parts = ['FREQ=' . $data['freq']];

        if (($data['interval'] ?? 1) > 1) {
            $parts[] = 'INTERVAL=' . (int) $data['interval'];
        }

        if (! empty($data['byday'])) {
            $parts[] = 'BYDAY=' . implode(',', $data['byday']);
        }

        if (! empty($data['bymonthday'])) {
            $parts[] = 'BYMONTHDAY=' . (int) $data['bymonthday'];
        }

        if (! empty($data['count'])) {
            $parts[] = 'COUNT=' . (int) $data['count'];
        }

        return implode(';', $parts);
    }
}
