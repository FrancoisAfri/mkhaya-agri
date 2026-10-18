<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmCopilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmCopilotController extends Controller
{
    public function __construct(private CrmCopilotService $copilot, private CrmAiService $ai)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Modules/CRM/Copilot', [
            'aiEnabled' => $this->ai->isConfigured(),
            'examples'  => [
                'Show at-risk deals with no activity in 14 days',
                'Which open deals are closing this month?',
                'Draft a follow-up email for my biggest open deal',
                'Summarise my pipeline health',
                'Which leads should I prioritise today?',
            ],
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate(['question' => 'required|string|max:1000']);

        $result = $this->copilot->ask($data['question']);

        // Account reference chips link to the detail page, which binds by uuid —
        // attach each referenced account's uuid (the AI only knows numeric ids).
        if (! empty($result['references'])) {
            $accountIds = collect($result['references'])->where('type', 'account')->pluck('id')->filter()->all();
            $uuids = $accountIds
                ? \App\Modules\CRM\Models\CrmAccount::whereIn('id', $accountIds)->pluck('uuid', 'id')
                : collect();
            $result['references'] = collect($result['references'])->map(function ($r) use ($uuids) {
                if (($r['type'] ?? null) === 'account' && isset($r['id'])) {
                    $r['uuid'] = $uuids[$r['id']] ?? null;
                }
                return $r;
            })->all();
        }

        return response()->json($result, ($result['available'] ?? false) && ! isset($result['error']) ? 200 : 422);
    }

    public function act(Request $request): JsonResponse
    {
        $action = $request->validate([
            'type'         => 'required|string',
            'deal_id'      => 'nullable|integer',
            'stage_name'   => 'nullable|string',
            'related_type' => 'nullable|string',
            'related_id'   => 'nullable|integer',
            'subject'      => 'nullable|string',
            'due_at'       => 'nullable|date',
        ]);

        return response()->json($this->copilot->applyAction($action));
    }
}
