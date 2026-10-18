<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\CrmReassignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One endpoint for handing CRM records to another sales person, from any of the four list screens.
 *
 * Deliberately shared rather than a reassign action per controller: the rules about who may move
 * what are identical for leads, deals, accounts and contacts, and four copies of them would be
 * four chances to get one wrong.
 */
class ReassignController extends Controller
{
    public function store(Request $request, CrmReassignService $reassign): RedirectResponse
    {
        $data = $request->validate([
            'type'                 => 'required|string|in:' . implode(',', array_keys(CrmReassignService::TYPES)),
            'ids'                  => 'required|array|min:1|max:500',
            'ids.*'                => 'required|integer',
            'owner_id'             => 'required|integer|exists:users,id',
            'move_open_activities' => 'nullable|boolean',
        ]);

        $result = $reassign->reassign(
            $data['type'],
            $data['ids'],
            (int) $data['owner_id'],
            (bool) ($data['move_open_activities'] ?? true),
        );

        if ($result['moved'] === 0) {
            return back()->with('success', 'Nothing to do — those records already belong to ' . $result['owner'] . '.');
        }

        $message = $result['moved'] . ' record' . ($result['moved'] === 1 ? '' : 's')
            . ' reassigned to ' . $result['owner'] . '.';

        if ($result['activities'] > 0) {
            $message .= ' ' . $result['activities'] . ' open '
                . ($result['activities'] === 1 ? 'task was' : 'tasks were') . ' moved across too.';
        }

        return back()->with('success', $message);
    }
}
