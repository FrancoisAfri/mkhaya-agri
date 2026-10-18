<?php

namespace App\Modules\Communication\ContentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Communication\ContentManagement\Models\Reaction;
use App\Modules\Communication\ContentManagement\Notifications\CommentAddedNotification;
use App\Modules\Communication\ContentManagement\Notifications\LikeAddedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReactionService
{
    /*
    |--------------------------------------------------------------------------
    | ADD REACTION
    |--------------------------------------------------------------------------
    */

    public function store(
        int $userId,
        ?int $contentId = null,
        ?int $employeeSpotlightId = null,
        bool $like = false,
        ?string $comment = null
    ): Reaction {
        return DB::transaction(function () use (
            $userId,
            $contentId,
            $employeeSpotlightId,
            $like,
            $comment,
        ) {

            $reaction = Reaction::create([
                'content_id'            => $contentId,
                'employee_spotlight_id' => $employeeSpotlightId,
                'like'                  => $like,
                'comment'               => $comment,
            ]);

            $reaction->users()->attach($userId);
            $reaction = $reaction->load(['users', 'content.author', 'employeeSpotlight.author', 'employeeSpotlight.employee']);

            // Send notifications for likes and comments
            if ($contentId) {
                $content = $reaction->content;
                if ($content && $content->author && $content->author->id !== $userId) {
                    if ($like) {
                        $content->author->notifyNow(new LikeAddedNotification($reaction, 'content'));
                    }
                    if ($comment) {
                        $content->author->notifyNow(new CommentAddedNotification($reaction, 'content'));
                    }
                }
            } elseif ($employeeSpotlightId) {
                $spotlight = $reaction->employeeSpotlight;
                if ($spotlight) {
                    $recipients = collect([]);

                    if ($spotlight->author && $spotlight->author->id !== $userId) {
                        $recipients->push($spotlight->author);
                    }

                    if ($spotlight->employee && $spotlight->employee->id !== $userId && $spotlight->employee->id) {
                        $recipients->push($spotlight->employee);
                    }

                    $recipients = $recipients->unique('id');

                    foreach ($recipients as $recipient) {
                        if ($like) {
                            $recipient->notifyNow(new LikeAddedNotification($reaction, 'spotlight'));
                        }
                        if ($comment) {
                            $recipient->notifyNow(new CommentAddedNotification($reaction, 'spotlight'));
                        }
                    }
                }
            }

            return $reaction;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | COUNT LIKES
    |--------------------------------------------------------------------------
    */

    public function countLikes(
        ?int $contentId = null,
        ?int $employeeSpotlightId = null
    ): int {

        return Reaction::query()
            ->when($contentId, function ($query) use ($contentId) {
                $query->where('content_id', $contentId);
            })
            ->when($employeeSpotlightId, function ($query) use ($employeeSpotlightId) {
                $query->where('employee_spotlight_id', $employeeSpotlightId);
            })
            ->where('like', true)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | USER REACTIONS
    |--------------------------------------------------------------------------
    */

    public function getUserReactions(int $userId): Collection
    {
        return User::findOrFail($userId)
            ->reactions()
            ->with([
                'content',
                'employeeSpotlight',
            ])
            ->latest()
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | REMOVE REACTION
    |--------------------------------------------------------------------------
    */

    public function removeReaction(int $reactionId): bool
    {
        $reaction = Reaction::findOrFail($reactionId);

        return $reaction->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK USER LIKE
    |--------------------------------------------------------------------------
    */

    public function hasUserLiked(
        int $userId,
        ?int $contentId = null,
        ?int $employeeSpotlightId = null
    ): bool {

        return Reaction::query()
            ->where('like', true)
            ->when($contentId, function ($query) use ($contentId) {
                $query->where('content_id', $contentId);
            })
            ->when($employeeSpotlightId, function ($query) use ($employeeSpotlightId) {
                $query->where('employee_spotlight_id', $employeeSpotlightId);
            })
            ->whereHas('users', function ($query) use ($userId) {
                $query->where('users.id', $userId);
            })
            ->exists();
    }


    public function getUserReactionId(
        int $userId,
        ?int $contentId = null,
        ?int $employeeSpotlightId = null
    ): ?int {
        return Reaction::query()
            ->where('like', true)
            ->when($contentId, fn($q) => $q->where('content_id', $contentId))
            ->when($employeeSpotlightId, fn($q) => $q->where('employee_spotlight_id', $employeeSpotlightId))
            ->whereHas('users', fn($q) => $q->where('users.id', $userId))
            ->value('id');
    }

public function getComments(
    ?int $contentId = null,
    ?int $employeeSpotlightId = null
): Collection {
    return Reaction::query()
        ->whereNotNull('comment')
        ->where('comment', '!=', '')
        ->when($contentId, fn($q) => $q->where('content_id', $contentId))
        ->when($employeeSpotlightId, fn($q) => $q->where('employee_spotlight_id', $employeeSpotlightId))
        ->with([
            'users',
            'users.person' => fn($q) => $q->select('user_id', 'first_name', 'surname'),
        ])
        ->latest()
        ->get()
        ->each(fn($reaction) => $reaction->users->each->makeHidden(['name']));
}
    /**
     * Compte les commentaires
     */
  public function countComments(
    ?int $contentId = null,
    ?int $employeeSpotlightId = null
): int {
    return Reaction::query()
        ->whereNotNull('comment')
        ->where('comment', '!=', '')
        ->when($contentId, fn($q) => $q->where('content_id', $contentId))
        ->when($employeeSpotlightId, fn($q) => $q->where('employee_spotlight_id', $employeeSpotlightId))
        ->count();
}
}
