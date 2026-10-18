<?php

namespace App\Modules\Communication\ContentManagement\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Communication\ContentManagement\Models\Reaction;
use App\Modules\Communication\ContentManagement\Requests\ReactionRequest;
use App\Modules\Communication\ContentManagement\Services\ReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReactionController extends Controller
{
    public function __construct(
        protected ReactionService $reactionService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | STORE REACTION
    |--------------------------------------------------------------------------
    */

    public function store(ReactionRequest $request)
    {
        $reaction = $this->reactionService->store(
            userId: Auth::id(),
            contentId: $request->content_id,
            employeeSpotlightId: $request->employee_spotlight_id,
            like: $request->boolean('like'),
            comment: $request->comment,
        );

        return back()->with('reaction_id', $reaction->id);
    }

    /*
    |--------------------------------------------------------------------------
    | USER REACTIONS
    |--------------------------------------------------------------------------
    */

    public function userReactions(): JsonResponse
    {
        $reactions = $this->reactionService
            ->getUserReactions(Auth::id());

        return response()->json([
            'success' => true,
            'data'    => $reactions,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | COUNT LIKES
    |--------------------------------------------------------------------------
    */

    public function countLikes(int $contentId): JsonResponse
    {
        $count = $this->reactionService
            ->countLikes(contentId: $contentId);

        return response()->json([
            'success' => true,
            'likes'   => $count,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE REACTION
    |--------------------------------------------------------------------------
    */

    public function destroy(int $reactionId)
    {
        $this->reactionService->removeReaction($reactionId);
        return back();
    }


public function comments(Request $request): JsonResponse
{
    $comments = $this->reactionService->getComments(
        contentId: $request->integer('content_id') ?: null,
        employeeSpotlightId: $request->integer('spotlight_id') ?: null,
    );

    return response()->json([
        'success' => true,
        'data'    => $comments->map(fn($r) => [
            'id'         => $r->id,
            'comment'    => $r->comment,
            'created_at' => $r->created_at->diffForHumans(),
            'user'       => $r->users->first() ? [
                'id'      => $r->users->first()->id,
                'name'    => $r->users->first()->person
                    ? trim(($r->users->first()->person->first_name ?? '') . ' ' . ($r->users->first()->person->surname ?? ''))
                    : $r->users->first()->email,
                'email'   => $r->users->first()->email,
                'initial' => strtoupper(
                    substr($r->users->first()->person?->first_name ?? $r->users->first()->email ?? '?', 0, 1)
                ),
            ] : null,
            'is_mine' => $r->users->contains('id', Auth::id()),
        ]),
    ]);
}


    public function destroyComment(int $reactionId): JsonResponse
    {
        $reaction = Reaction::findOrFail($reactionId);

        if (!$reaction->users()->where('users.id', Auth::id())->exists()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $reaction->delete();
        return response()->json(['success' => true]);
    }
}
