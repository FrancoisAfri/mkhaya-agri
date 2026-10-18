<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * In-app CRM notification feed (spec section 10).
 */
class NotificationController extends Controller
{
    public function feed(): JsonResponse
    {
        $items = CrmNotification::where('user_id', Auth::id())->latest()->limit(30)->get()
            ->map(fn ($n) => [
                'id'    => $n->id,
                'type'  => $n->type,
                'title' => $n->title,
                'body'  => $n->body,
                'link'  => $n->link,
                'read'  => $n->read_at !== null,
                'at'    => $n->created_at?->diffForHumans(),
            ]);

        return response()->json([
            'items'  => $items,
            'unread' => CrmNotification::where('user_id', Auth::id())->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(CrmNotification $notification): RedirectResponse
    {
        abort_if($notification->user_id !== Auth::id(), 403);
        $notification->update(['read_at' => now()]);

        return back();
    }

    public function markAllRead(): RedirectResponse
    {
        CrmNotification::where('user_id', Auth::id())->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked read.');
    }
}
