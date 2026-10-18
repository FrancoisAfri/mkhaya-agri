<?php

namespace App\Modules\Communication\ContentManagement\Notifications;

use App\Modules\Communication\ContentManagement\Models\EmployeeSpotlight;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SpotlightCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected EmployeeSpotlight $spotlight
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $author = $this->spotlight->author;
        $authorName = $author?->person?->full_name ?? $author?->email ?? 'Someone';

        $typeLabel = match($this->spotlight->type) {
            1 => 'award',
            2 => 'anniversary',
            3 => 'team highlight',
            default => 'spotlight',
        };

        return [
            'type' => 'spotlight_created',
            'message' => "{$authorName} created a {$typeLabel} spotlight: \"{$this->spotlight->title}\"",
            'author_name' => $authorName,
            'author_id' => $author?->id,
            'spotlight_id' => $this->spotlight->id,
            'spotlight_title' => $this->spotlight->title,
            'spotlight_type' => $typeLabel,
            'url' => '/dashboard',
        ];
    }
}
