<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmStage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CRM Copilot — answers natural-language questions over the user's (visibility-
 * scoped) CRM data and proposes safe actions to confirm. Uses a full-context
 * "RAG-lite" snapshot rather than a vector store, which is plenty for typical
 * data volumes and keeps it dependency-free.
 */
class CrmCopilotService
{
    public function __construct(
        private CrmAiService $ai,
        private CrmVisibilityService $visibility,
    ) {}

    public function ask(string $question): array
    {
        $result = $this->ai->copilot($question, $this->buildContext());
        return $result;
    }

    /** Execute a confirmed action returned by the copilot. Drafts are returned, not sent. */
    public function applyAction(array $action): array
    {
        $owners = $this->visibility->visibleOwnerIds();
        $canSee = fn ($ownerId) => $owners === null || in_array((int) $ownerId, $owners, true);

        switch ($action['type'] ?? 'none') {
            case 'update_deal_stage':
                $deal = CrmDeal::find($action['deal_id'] ?? 0);
                if (! $deal || ! $canSee($deal->owner_id)) return ['ok' => false, 'message' => 'Deal not found or not visible.'];
                $stage = CrmStage::where('pipeline_id', $deal->pipeline_id)
                    ->where('name', 'ilike', $action['stage_name'] ?? '')->first();
                if (! $stage) return ['ok' => false, 'message' => 'Stage "'.($action['stage_name'] ?? '').'" not found in this pipeline.'];
                $deal->update([
                    'stage_id' => $stage->id, 'probability' => $stage->probability, 'stage_changed_at' => now(),
                    'status' => $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open'),
                    'closed_at' => ($stage->is_won || $stage->is_lost) ? now() : null,
                ]);
                return ['ok' => true, 'message' => "Moved “{$deal->title}” to {$stage->name}."];

            case 'create_task':
                CrmActivity::create([
                    'uuid' => (string) Str::uuid(),
                    'type' => 'task',
                    'subject' => $action['subject'] ?? 'Follow up',
                    'related_type' => $action['related_type'] ?? 'deal',
                    'related_id' => (int) ($action['related_id'] ?? 0),
                    'status' => 'pending',
                    'due_at' => $action['due_at'] ?? now()->addDay(),
                    'owner_id' => Auth::id(),
                    'created_by' => Auth::id(),
                ]);
                return ['ok' => true, 'message' => 'Task created.'];

            default:
                return ['ok' => false, 'message' => 'This action must be done manually.'];
        }
    }

    /** Build a compact JSON snapshot of the user's visible CRM data. */
    private function buildContext(): string
    {
        $owners = $this->visibility->visibleOwnerIds();
        $scope  = fn ($q) => $owners !== null ? $q->whereIn('owner_id', $owners) : $q;

        // Last activity date per deal (for "no activity in N days" questions).
        $dealActivity = $scope(CrmActivity::where('related_type', 'deal'))
            ->select('related_id', DB::raw('max(created_at) as last'))
            ->groupBy('related_id')->pluck('last', 'related_id');

        $deals = $scope(CrmDeal::with(['account:id,name', 'stage:id,name', 'owner.person']))
            ->whereIn('status', ['open', 'won', 'lost'])
            ->latest('updated_at')->limit(150)->get()
            ->map(function ($d) use ($dealActivity) {
                $last = $dealActivity[$d->id] ?? null;
                return [
                    'id' => $d->id, 'title' => $d->title, 'account' => $d->account?->name,
                    'industry' => $d->account?->industry, 'stage' => $d->stage?->name, 'status' => $d->status,
                    'amount' => (float) $d->amount, 'currency' => $d->currency, 'owner' => $d->owner?->person?->first_name,
                    'win_probability' => $d->ai_win_probability, 'risk' => $d->risk_level,
                    'expected_close' => $d->expected_close_date?->toDateString(),
                    'days_since_activity' => $last ? \Carbon\Carbon::parse($last)->diffInDays(now()) : null,
                    'days_in_stage' => $d->stage_changed_at?->diffInDays(now()),
                ];
            });

        $leads = $scope(CrmLead::with('owner.person'))->whereNotIn('status', ['converted', 'unqualified'])
            ->latest()->limit(100)->get()
            ->map(fn ($l) => [
                'id' => $l->id, 'name' => trim($l->first_name.' '.$l->last_name), 'company' => $l->company,
                'status' => $l->status, 'score' => $l->score, 'owner' => $l->owner?->person?->first_name,
            ]);

        $accounts = CrmAccount::withCount(['deals'])->orderBy('name')->limit(150)->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'industry' => $a->industry,
                'annual_revenue' => $a->annual_revenue !== null ? (float) $a->annual_revenue : null, 'deals' => $a->deals_count,
            ]);

        $activities = $scope(CrmActivity::with('owner.person'))->latest()->limit(60)->get()
            ->map(fn ($x) => [
                'id' => $x->id, 'type' => $x->type, 'subject' => $x->subject,
                'related' => $x->related_type.'#'.$x->related_id, 'owner' => $x->owner?->person?->first_name,
                'when' => $x->created_at?->diffForHumans(),
            ]);

        return json_encode([
            'today'      => now()->toDateString(),
            'deals'      => $deals,
            'leads'      => $leads,
            'accounts'   => $accounts,
            'activities' => $activities,
        ]);
    }
}
