<?php

namespace App\Modules\Communication\ContentManagement\Notifications;

use App\Modules\Communication\ContentManagement\Models\Reaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LikeAddedNotification extends Notification implements ShouldQueue
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
        $liker = $this->reaction->users->first();
        $likerName = $liker?->person?->full_name ?? $liker?->email ?? 'Someone';

        if ($this->contentType === 'spotlight') {
            $spotlight = $this->reaction->employeeSpotlight;
            return [
                'type' => 'like_added_spotlight',
                'message' => "{$likerName} liked your spotlight: \"{$spotlight->title}\"",
                'liker_name' => $likerName,
                'liker_id' => $liker?->id,
                'spotlight_id' => $spotlight->id,
                'url' => '/dashboard',
            ];
        }

        // For content
        $content = $this->reaction->content;
        return [
            'type' => 'like_added_content',
            'message' => "{$likerName} liked your post: \"{$content->title}\"",
            'liker_name' => $likerName,
            'liker_id' => $liker?->id,
            'content_id' => $content->id,
            'url' => '/news-and-events',
        ];
    }
}
