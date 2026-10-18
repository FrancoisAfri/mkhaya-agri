<?php

namespace App\Modules\Communication\ContentManagement\Notifications;

use App\Modules\Communication\ContentManagement\Models\Reaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CommentAddedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Reaction $reaction,
        protected string $contentType = 'content' // 'content' or 'spotlight'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $commenter = $this->reaction->users->first();
        $commenterName = $commenter?->person?->full_name ?? $commenter?->email ?? 'Someone';

        if ($this->contentType === 'spotlight') {
            $spotlight = $this->reaction->employeeSpotlight;
            return [
                'type' => 'comment_added_spotlight',
                'message' => "{$commenterName} commented on your spotlight: \"{$spotlight->title}\"",
                'comment' => $this->reaction->comment,
                'commenter_name' => $commenterName,
                'commenter_id' => $commenter?->id,
                'spotlight_id' => $spotlight->id,
                'url' => '/dashboard',
            ];
        }

        // For content
        $content = $this->reaction->content;
        return [
            'type' => 'comment_added_content',
            'message' => "{$commenterName} commented on your post: \"{$content->title}\"",
            'comment' => $this->reaction->comment,
            'commenter_name' => $commenterName,
            'commenter_id' => $commenter?->id,
            'content_id' => $content->id,
            'url' => '/news-and-events',
        ];
    }
}
