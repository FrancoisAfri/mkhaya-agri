<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use App\Modules\PerformanceAppraisal\Models\PaCheckinActionItem;
use App\Modules\PerformanceAppraisal\Models\PaCheckinTalkingPoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Best-in-class 1:1 layer: collaborative talking points + structured action
 * items (with owners, due dates and carry-over) on top of pa_checkins.
 */
class PaOneOnOneController extends Controller
{
    private function authorRole(PaCheckin $checkin): string
    {
        return Auth::id() === $checkin->manager_id ? 'manager' : 'employee';
    }

    public function addTalkingPoint(Request $request, PaCheckin $checkin): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:1000']);

        PaCheckinTalkingPoint::create([
            'checkin_id'  => $checkin->id,
            'created_by'  => Auth::id(),
            'author_role' => $this->authorRole($checkin),
            'body'        => $data['body'],
            'sort_order'  => (int) (PaCheckinTalkingPoint::where('checkin_id', $checkin->id)->max('sort_order') + 1),
        ]);

        return back()->with('success', 'Talking point added.');
    }

    public function toggleTalkingPoint(PaCheckinTalkingPoint $talkingPoint): RedirectResponse
    {
        $talkingPoint->update(['is_discussed' => ! $talkingPoint->is_discussed]);

        return back();
    }

    public function deleteTalkingPoint(PaCheckinTalkingPoint $talkingPoint): RedirectResponse
    {
        $talkingPoint->delete();

        return back();
    }

    public function addActionItem(Request $request, PaCheckin $checkin): RedirectResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:1000',
            'owner_id'    => 'nullable|exists:users,id',
            'due_date'    => 'nullable|date',
        ]);

        PaCheckinActionItem::create([
            'checkin_id'  => $checkin->id,
            'owner_id'    => $data['owner_id'] ?? $checkin->employee_id,
            'description' => $data['description'],
            'due_date'    => $data['due_date'] ?? null,
            'status'      => 'open',
        ]);

        return back()->with('success', 'Action item added.');
    }

    public function updateActionItem(Request $request, PaCheckinActionItem $actionItem): RedirectResponse
    {
        $data = $request->validate([
            'status'      => 'nullable|in:open,done,carried_over',
            'description' => 'nullable|string|max:1000',
            'due_date'    => 'nullable|date',
            'owner_id'    => 'nullable|exists:users,id',
        ]);

        if (($data['status'] ?? null) === 'done' && $actionItem->status !== 'done') {
            $data['completed_at'] = now();
        }

        $actionItem->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'Action item updated.');
    }

    public function deleteActionItem(PaCheckinActionItem $actionItem): RedirectResponse
    {
        $actionItem->delete();

        return back();
    }

    /**
     * Carry all open action items from one check-in into a (new) follow-up
     * check-in — the classic 1:1 continuity feature.
     */
    public function carryOver(Request $request, PaCheckin $checkin): RedirectResponse
    {
        $data = $request->validate(['target_checkin_id' => 'required|exists:pa_checkins,id']);

        $open = PaCheckinActionItem::where('checkin_id', $checkin->id)
            ->where('status', 'open')->get();

        foreach ($open as $item) {
            PaCheckinActionItem::create([
                'checkin_id'      => $data['target_checkin_id'],
                'owner_id'        => $item->owner_id,
                'description'     => $item->description,
                'due_date'        => $item->due_date,
                'status'          => 'open',
                'carried_from_id' => $item->id,
            ]);
            $item->update(['status' => 'carried_over']);
        }

        return back()->with('success', "{$open->count()} action item(s) carried over.");
    }
}
