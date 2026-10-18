<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentComment;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\CommentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Commenting on a document.
 *
 * Gated on reading the document rather than on a permission of its own. Somebody who can read a
 * contract can say something about it — requiring a separate right to speak would mean the people
 * who notice the problem are the ones who cannot report it.
 */
class DocumentCommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    public function store(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent' => ['nullable', 'string'],
            'version' => ['nullable', 'string'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer'],
        ]);

        /*
         * The parent is resolved within this document. Without the check, a uuid from another
         * document would attach a reply to a thread the author cannot see — and the reply would be
         * readable by everybody who can read that other document.
         */
        $parent = filled($data['parent'] ?? null)
            ? DocumentComment::where('uuid', $data['parent'])->where('document_id', $document->id)->first()
            : null;

        $version = filled($data['version'] ?? null)
            ? DocumentVersion::where('uuid', $data['version'])->where('document_id', $document->id)->first()
            : null;

        $this->comments->add(
            $document,
            $data['body'],
            $parent,
            $version,
            $data['mentions'] ?? [],
        );

        return back()->with('success', __('document_management.comments.added'));
    }

    /**
     * Settles a thread, or reopens one.
     *
     * Anybody who can read the document, deliberately. A thread stays open until somebody says the
     * question is answered, and the person who knows that is usually not the document's owner.
     */
    public function resolve(Request $request, Document $document, DocumentComment $comment): RedirectResponse
    {
        $this->authorize('view', $document);

        abort_unless($comment->document_id === $document->id, 404);

        if ($request->boolean('reopen')) {
            $this->comments->reopen($comment);

            return back()->with('success', __('document_management.comments.reopened'));
        }

        $this->comments->resolve($comment, $request->user());

        return back()->with('success', __('document_management.comments.resolved'));
    }

    /**
     * Removes a remark.
     *
     * Only the author, and only their own. A comment somebody else can delete is a record nobody
     * can rely on, and the point of keeping the conversation here is that it stays.
     */
    public function destroy(Request $request, Document $document, DocumentComment $comment): RedirectResponse
    {
        $this->authorize('view', $document);

        abort_unless($comment->document_id === $document->id, 404);
        abort_unless((int) $comment->created_by === (int) $request->user()->id, 403);

        // The replies go with it: a reply to a remark that is gone reads as an answer to nothing.
        DocumentComment::where('parent_id', $comment->id)->delete();
        $comment->delete();

        return back()->with('success', __('document_management.comments.deleted'));
    }
}
