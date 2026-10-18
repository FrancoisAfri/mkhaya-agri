<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\AssistantDesk;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAssistant;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-140..144: the warehouse assistant -- questions, drafts, feedback and its own quality. */
class AssistantController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request, WarehouseAssistant $assistant): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $user = $request->user();

        return Inertia::render('Modules/Warehouse/Assistant/Index', [
            // Your own questions only: what others asked, and what they were told, is theirs.
            'history' => DB::table('wms_assistant_queries')->where('user_id', $user->id)->orderByDesc('id')->limit(20)->get()
                ->map(fn ($q) => ['id' => $q->uuid, 'question' => $q->question, 'answer' => json_decode($q->answer, true), 'narration' => $q->narration,
                    'source' => $q->narration_source, 'at' => $q->created_at,
                    'drafts' => DB::table('wms_assistant_actions')->where('query_id', $q->id)->get(['uuid', 'rec_index', 'kind', 'status', 'result_reference'])
                        ->map(fn ($a) => (array) $a)->all()]),
            'suggestions' => collect(WarehouseAssistant::TOPICS)->filter(fn ($t, $k) => $assistant->allowed($user, $k))->map(fn ($t) => $t[0])->values(),
            'can' => ['quality' => $user->can(RoleCatalog::SUPERVISE)],
        ]);
    }

    public function ask(Request $request, AssistantDesk $desk): RedirectResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $data = $request->validate(['question' => ['required', 'string', 'max:500']]);
        $desk->ask($data['question'], $request->user());

        return back();
    }

    public function draft(Request $request, AssistantDesk $desk): RedirectResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $data = $request->validate(['query' => ['required', 'string'], 'rec' => ['required', 'integer', 'min:0'],
            'kind' => ['required', Rule::in(['COUNT', 'TRANSFER_DRAFT', 'PURCHASE_REQUEST', 'INVESTIGATION', 'TASK'])]]);
        $made = null;
        $response = $this->attempt(function () use ($desk, $data, $request, &$made) {
            $made = $desk->draft($data['query'], (int) $data['rec'], $data['kind'], $request->user());
        }, 'Draft made.');

        return $made && $made->result_reference ? back()->with('success', "Draft made: {$made->result_reference}.") : $response;
    }

    public function withdraw(Request $request, string $uuid, AssistantDesk $desk): RedirectResponse
    {
        $this->authorize('viewAny', FinStockItem::class);

        return $this->attempt(fn () => $desk->withdraw($uuid, $request->user(), (string) $request->input('reason', 'Not needed')), 'Draft withdrawn.');
    }

    public function feedback(Request $request, string $uuid, AssistantDesk $desk): RedirectResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $data = $request->validate(['verdict' => ['required', Rule::in(['CORRECT', 'INCORRECT', 'USEFUL', 'NOT_USEFUL'])], 'rec' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $desk->feedback($uuid, $data['rec'] ?? null, $data['verdict'], $data['note'] ?? null, $request->user()), 'Thank you; noted.');
    }

    public function quality(Request $request, AssistantDesk $desk): Response
    {
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE), 403);
        $from = $request->input('from', now()->subDays(30)->toDateString());
        $to = $request->input('to', now()->toDateString());
        $cid = (int) (app(CurrentCompany::class)->id() ?? $request->user()->company_id);

        return Inertia::render('Modules/Warehouse/Assistant/Quality', [
            'quality' => $desk->quality($cid, $from, $to),
            'flagged' => DB::table('wms_assistant_feedback as f')->join('wms_assistant_queries as q', 'q.id', '=', 'f.query_id')->where('f.company_id', $cid)
                ->whereIn('f.verdict', ['INCORRECT', 'NOT_USEFUL'])->orderByDesc('f.id')->limit(30)->get(['q.question', 'q.topic', 'f.verdict', 'f.note', 'f.created_at'])->map(fn ($r) => (array) $r),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }
}
