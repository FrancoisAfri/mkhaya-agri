<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Services\CrmRecordOptions;
use App\Modules\CRM\Services\CrmTelephonyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Click-to-call. Dials through whichever VoIP provider is active and logs the
 * call as a CRM activity against the related record. Also serves the Calls page
 * (dialer + call log + per-period statistics).
 */
class CallController extends Controller
{
    public function __construct(private CrmTelephonyService $telephony)
    {
    }

    /** The Calls workspace: dialer, call log and statistics. */
    public function index(Request $request, CrmRecordOptions $records): Response
    {
        $userId = (int) Auth::id();
        $mine   = ! $request->boolean('all');

        $logQuery = CrmActivity::with('owner.person')
            ->where('type', 'call')
            ->when($mine, fn ($q) => $q->where('owner_id', $userId));

        // "Show all" is still bounded by what the user may see (team / own / all).
        if (! $mine) {
            app(\App\Modules\CRM\Services\CrmVisibilityService::class)->scopeOwner($logQuery);
        }

        $log = $logQuery->latest()
            ->limit(200)
            ->get()
            ->map(fn ($a) => [
                'id'         => $a->id,
                'subject'    => $a->subject,
                'related'    => $a->related_type.' #'.$a->related_id,
                'owner'      => $a->owner?->person?->first_name,
                'created_at' => $a->created_at?->diffForHumans(),
                'at'         => $a->created_at?->toDateTimeString(),
            ]);

        $count = fn ($from) => CrmActivity::where('type', 'call')->where('owner_id', $userId)
            ->where('created_at', '>=', $from)->count();

        return Inertia::render('Modules/CRM/Calls', [
            'log'   => $log,
            'mine'  => $mine,
            'stats' => [
                'today' => $count(now()->startOfDay()),
                'week'  => $count(now()->startOfWeek()),
                'month' => $count(now()->startOfMonth()),
                'total' => CrmActivity::where('type', 'call')->where('owner_id', $userId)->count(),
            ],
            'provider'      => $this->telephony->isConfigured() ? ($this->telephony->activeIntegration()?->provider) : null,
            'recordOptions' => $records->forTypes(['lead', 'contact', 'deal']),
        ]);
    }

    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to'           => 'required|string|max:40',
            'name'         => 'nullable|string|max:200',
            'related_type' => 'nullable|in:lead,contact,account,deal',
            'related_id'   => 'nullable|integer',
        ]);

        $result = $this->telephony->dial($data['to']);

        if (! $result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        // Log the call so it appears on the timeline / account 360 view.
        if (! empty($data['related_type']) && ! empty($data['related_id'])) {
            CrmActivity::create([
                'uuid'         => Str::uuid(),
                'type'         => 'call',
                'subject'      => 'Call to '.($data['name'] ?: $data['to']).' via '.($result['label'] ?? 'phone'),
                'description'  => 'Outbound call placed from the CRM ('.($result['mode'] ?? 'tel').').',
                'related_type' => $data['related_type'],
                'related_id'   => $data['related_id'],
                'status'       => 'done',
                'completed_at' => now(),
                'owner_id'     => Auth::id(),
                'created_by'   => Auth::id(),
            ]);
        }

        return response()->json([
            'success' => true,
            'mode'    => $result['mode'] ?? 'tel',   // sip | tel | api
            'uri'     => $result['uri'] ?? null,      // browser opens this for sip/tel
            'message' => $result['message'],
        ]);
    }
}
