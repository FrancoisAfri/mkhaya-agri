<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ChatMessage;
use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The project conversation (§19).
 *
 * Polled rather than pushed. Broadcasting would need a websocket server this deployment does not
 * run, and a chat that silently stops updating because a socket dropped is worse than one that
 * refreshes every few seconds and is honest about it. When the platform gains broadcasting, the
 * read endpoint is the only thing that changes.
 */
class ChatController extends Controller
{
    /** How many messages a first load carries. Enough to scroll back through a morning. */
    private const PAGE = 60;

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $messages = ChatMessage::where('project_id', $project->id)
            // Only what the caller has not seen, so a poll is cheap.
            ->when($request->query('after'), fn ($q, $id) => $q->where('id', '>', (int) $id))
            ->orderByDesc('id')
            ->limit(self::PAGE)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'messages' => $messages->map(fn (ChatMessage $m) => [
                'id'      => $m->id,
                'uuid'    => $m->uuid,
                'author'  => $m->author_name,
                'user_id' => $m->user_id,
                'body'    => $m->body,
                'at'      => $m->created_at->toIso8601String(),
                'mine'    => $m->user_id === $request->user()->id,
            ])->all(),
            'last' => $messages->last()['id'] ?? $request->query('after'),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $person = $request->user()->person;

        ChatMessage::create([
            'project_id'  => $project->id,
            'user_id'     => $request->user()->id,
            // Snapshotted: a message from somebody who has left still has to read as theirs.
            'author_name' => trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
                ?: (string) $request->user()->email,
            'body'        => $data['body'],
        ]);

        return back();
    }

    /**
     * Remove your own message.
     *
     * Only your own, and only ever a soft delete: a conversation somebody can edit after the fact
     * is not a record of what was said.
     */
    public function destroy(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('view', $project);

        $message = ChatMessage::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        abort_unless($message->user_id === $request->user()->id, 403);

        $message->delete();

        return back();
    }
}
