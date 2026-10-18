<?php

namespace App\Modules\Communication\ContentManagement\Notifications;

use App\Modules\Communication\ContentManagement\Models\Content;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ContentCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Content $content
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $author = $this->content->author;
        $authorName = $author?->person?->full_name ?? $author?->email ?? 'Someone';

        $typeLabel = match($this->content->type) {
            1 => 'announcement',
            2 => 'news',
            3 => 'event',
            4 => 'internal notice',
            default => 'post',
        };

        return [
            'type' => 'content_created',
            'message' => "{$authorName} posted a new {$typeLabel}: \"{$this->content->title}\"",
            'author_name' => $authorName,
            'author_id' => $author?->id,
            'content_id' => $this->content->id,
            'content_title' => $this->content->title,
            'content_type' => $typeLabel,
            'url' => '/news-and-events',
        ];
    }
}
