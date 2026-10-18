<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmNotification;
use Illuminate\Support\Facades\Auth;

/**
 * In-app notifications for the CRM collaboration layer (spec section 10).
 */
class CrmNotifier
{
    public function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, ?string $entityType = null, ?int $entityId = null): void
    {
        // Don't notify yourself.
        if ($userId === Auth::id()) return;

        CrmNotification::create([
            'user_id'     => $userId,
            'type'        => $type,
            'title'       => $title,
            'body'        => $body,
            'link'        => $link,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'created_by'  => Auth::id(),
        ]);
    }

    /**
     * Notify a list of @mentioned users.
     *
     * @param  array<int>  $userIds
     */
    public function mention(array $userIds, string $title, ?string $body = null, ?string $link = null, ?string $entityType = null, ?int $entityId = null): void
    {
        foreach (array_unique($userIds) as $userId) {
            $this->notify((int) $userId, 'mention', $title, $body, $link, $entityType, $entityId);
        }
    }
}
