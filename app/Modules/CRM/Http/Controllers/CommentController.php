<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmComment;
use App\Modules\CRM\Services\CrmNotifier;
use App\Modules\CRM\Services\CrmRecordOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team collaboration — threaded comments / discussions on CRM records, with
 * @mention notifications (spec section 10).
 */
class CommentController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(
        private CrmNotifier $notifier,
        private CrmRecordOptions $recordOptions,
    ) {
    }

    public function index(): Response
    {
        $threads = CrmComment::with(['author.person', 'replies.author.person'])
            ->whereNull('parent_id')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($c) => $this->present($c, true));

        return Inertia::render('Modules/CRM/Discussions', [
            'threads'        => $threads,
            'entityTypes'    => ['lead', 'contact', 'account', 'deal'],
            'relatedOptions' => $this->recordOptions->forTypes(['lead', 'contact', 'account', 'deal']),
            'users'          => $this->tenantPeopleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entity_type'  => 'required|in:lead,contact,account,deal,object',
            'entity_id'    => 'required|integer',
            'entity_label' => 'nullable|string|max:200',
            'parent_id'    => 'nullable|exists:crm_comments,id',
            'body'         => 'required|string|max:4000',
            'mentioned'    => 'nullable|array',
            'mentioned.*'  => 'integer|exists:users,id',
        ]);

        $comment = CrmComment::create([
            'uuid'         => Str::uuid(),
            'entity_type'  => $data['entity_type'],
            'entity_id'    => $data['entity_id'],
            'entity_label' => $data['entity_label'] ?? null,
            'parent_id'    => $data['parent_id'] ?? null,
            'body'         => $data['body'],
            'mentioned'    => $data['mentioned'] ?? [],
            'author_id'    => Auth::id(),
        ]);

        // Notify mentioned users + (on a reply) the parent author.
        $this->notifier->mention(
            $data['mentioned'] ?? [],
            'You were mentioned in a discussion',
            Str::limit($comment->body, 120),
            route('crm.discussions', absolute: false),
            $comment->entity_type, $comment->entity_id,
        );

        if ($comment->parent_id) {
            $parentAuthor = CrmComment::find($comment->parent_id)?->author_id;
            if ($parentAuthor) {
                $this->notifier->notify(
                    (int) $parentAuthor, 'mention', 'New reply to your comment',
                    Str::limit($comment->body, 120),
                    route('crm.discussions', absolute: false),
                    $comment->entity_type, $comment->entity_id,
                );
            }
        }

        return back()->with('success', 'Comment posted.');
    }

    public function destroy(CrmComment $comment): RedirectResponse
    {
        abort_if($comment->author_id !== Auth::id(), 403, 'You can only delete your own comments.');
        $comment->delete();

        return back()->with('success', 'Comment deleted.');
    }

    private function present(CrmComment $c, bool $withReplies = false): array
    {
        $data = [
            'id'           => $c->id,
            'entity_type'  => $c->entity_type,
            'entity_id'    => $c->entity_id,
            'entity_label' => $c->entity_label,
            'body'         => $c->body,
            'author'       => trim(($c->author?->person?->first_name ?? '') . ' ' . ($c->author?->person?->surname ?? '')) ?: 'User',
            'mine'         => $c->author_id === Auth::id(),
            'at'           => $c->created_at?->diffForHumans(),
        ];

        if ($withReplies) {
            $data['replies'] = $c->replies->map(fn ($r) => $this->present($r))->all();
        }

        return $data;
    }
}
