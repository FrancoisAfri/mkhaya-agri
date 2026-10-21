<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\AgriAiDraft;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §10.3: what the assistant may prepare, and the line it does not cross.
 *
 * It proposes a work order, a purchase requisition, an inspection or a management report, each
 * with the records that prompted it. A proposal does nothing. A person accepts it, and only if they
 * hold the permission the real action needs; even then what it makes is a draft that goes through
 * the usual approval (a draft work order, a requisition waiting to be submitted). Payments,
 * pesticide applications, payroll changes, financial postings, stock disposals and starting
 * machinery are refused outright, whoever asks.
 */
class AgriDrafts
{
    /** kind => the permission accepting it needs */
    private const NEEDS = ['work_order' => R::MANAGE_WORK_ORDERS, 'requisition' => R::RECEIVE_STOCK, 'inspection' => R::MANAGE_MACHINERY, 'report' => R::USE_ASSISTANT];

    /** Words that ask the assistant to act where it may not. */
    private const FORBIDDEN = [
        'payment' => ['pay ', 'pay the', 'make a payment', 'transfer money', 'eft', 'settle the invoice'],
        'pesticide_application' => ['spray now', 'apply the pesticide', 'apply pesticide', 'start spraying', 'apply herbicide', 'apply fungicide'],
        'payroll_change' => ['change payroll', 'change the payroll', 'increase wage', 'change wage', 'change salary', 'raise salary'],
        'journal' => ['post a journal', 'adjust the books', 'change the ledger', 'write off the invoice', 'reverse the invoice'],
        'stock_disposal' => ['dispose of', 'write off the stock', 'destroy the stock', 'scrap the stock'],
        'machine_activation' => ['start the tractor', 'start the machine', 'switch on the pump', 'turn on the pump', 'start the pump', 'activate the'],
    ];

    /** The action a question asks for that the assistant may never take, if any. */
    public function forbidden(string $question): ?string
    {
        $q = ' '.mb_strtolower($question).' ';
        foreach (self::FORBIDDEN as $kind => $words) {
            foreach ($words as $w) {
                if (str_contains($q, $w)) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /** Keep the assistant's proposals for this person; the same open proposal is not repeated. */
    public function propose(array $proposals, User $user): array
    {
        $out = [];
        foreach ($proposals as $p) {
            if (! in_array($p['kind'] ?? null, AgriAiDraft::KINDS, true)) {
                continue;
            }
            $farm = ! empty($p['farm']) ? Farm::query()->where('name', $p['farm'])->first() : null;
            $out[] = AgriAiDraft::query()->firstOrCreate(['company_id' => $user->company_id, 'created_for' => $user->id, 'kind' => $p['kind'], 'title' => mb_strimwidth($p['title'], 0, 200),
                'status' => 'proposed'], ['farm_id' => $farm?->id, 'payload' => $p['payload'] ?? [], 'reason' => mb_strimwidth($p['reason'] ?? '', 0, 500)]);
        }

        return $out;
    }

    public function accept(AgriAiDraft $d, User $actor): AgriAiDraft
    {
        if (in_array($d->kind, AgriAiDraft::NEVER, true)) {
            throw ValidationException::withMessages(['draft' => 'The assistant never takes that kind of action.']);
        }
        if ($d->status !== 'proposed') {
            throw ValidationException::withMessages(['draft' => "That proposal is {$d->status}."]);
        }
        if (! $actor->can(self::NEEDS[$d->kind])) {
            throw ValidationException::withMessages(['draft' => 'Your permissions do not cover this; ask someone who can '.str_replace('_', ' ', $d->kind === 'requisition' ? 'raise requisitions' : "create a {$d->kind}").'.']);
        }
        $farm = $d->farm_id ? Farm::query()->find($d->farm_id) : null;
        if ($d->farm_id && ! $farm) {
            throw ValidationException::withMessages(['draft' => 'That farm is not one you can open.']);
        }
        if ($d->kind !== 'report' && ! $farm) {
            throw ValidationException::withMessages(['draft' => 'The proposal names no farm.']);
        }
        if ($farm && $d->kind !== 'report' && ! app(FarmAccess::class)->canWrite($actor, $farm->id)) {
            throw ValidationException::withMessages(['draft' => "You can view {$farm->name} but not change it."]);
        }

        return DB::transaction(function () use ($d, $actor, $farm) {
            [$type, $id] = match ($d->kind) {
                'work_order' => ['farm_work_order', app(WorkOrders::class)->create($farm, [
                    'category' => $d->payload['category'] ?? 'other', 'title' => $d->payload['title'] ?? $d->title, 'priority' => 'normal',
                    'instructions' => "Proposed by the farm assistant: {$d->reason}",
                    'crop_plan_id' => ! empty($d->payload['plan']) ? CropPlan::query()->where('farm_id', $farm->id)->where('code', $d->payload['plan'])->value('id') : null,
                    'planned_start' => now()->addDay()->startOfHour()->toDateTimeString(), 'planned_end' => now()->addDay()->startOfHour()->addHours(4)->toDateTimeString(),
                ], $actor)->id],
                'inspection' => ['farm_work_order', app(WorkOrders::class)->create($farm, [
                    'category' => 'machinery_service', 'title' => $d->title, 'priority' => 'high', 'instructions' => "Inspection proposed by the farm assistant: {$d->reason}",
                    'machine_id' => FarmMachine::query()->where('farm_id', $farm->id)->where('uuid', $d->payload['machine_id'] ?? '')->value('id'),
                    'planned_start' => now()->addDay()->startOfHour()->toDateTimeString(), 'planned_end' => now()->addDay()->startOfHour()->addHours(2)->toDateTimeString(),
                ], $actor)->id],
                'requisition' => ['purchase_request', app(FarmProcurement::class)->requisition($farm, ['justification' => $d->payload['justification'] ?? $d->reason,
                    'lines' => $d->payload['lines'] ?? []], $actor)->id],
                'report' => ['farm_document', $this->report($d, $actor, $farm)],
            };
            $d->update(['status' => 'accepted', 'decided_by' => $actor->id, 'decided_at' => now(), 'result_type' => $type, 'result_id' => $id]);

            return $d;
        });
    }

    public function discard(AgriAiDraft $d, User $actor): AgriAiDraft
    {
        if ($d->status !== 'proposed') {
            throw ValidationException::withMessages(['draft' => "That proposal is {$d->status}."]);
        }
        $d->update(['status' => 'discarded', 'decided_by' => $actor->id, 'decided_at' => now()]);

        return $d;
    }

    /** A management report: the assistant's answer as it stood, kept as a farm document. */
    private function report(AgriAiDraft $d, User $actor, ?Farm $farm): int
    {
        $farm ??= Farm::query()->orderBy('name')->firstOrFail();
        $a = $d->payload;
        $lines = ["# {$d->title}", '', 'Prepared by the farm assistant on '.now()->format('Y-m-d H:i').' for '.$actor->name.'.', '', $a['summary'] ?? '', '',
            'Period: '.($a['period'] ?? '—'), 'Sources: '.implode('; ', $a['sources'] ?? []), 'Assumptions: '.implode('; ', $a['assumptions'] ?? []), ''];
        if (! empty($a['columns'])) {
            $lines[] = '| '.implode(' | ', $a['columns']).' |';
            $lines[] = '|'.str_repeat(' --- |', count($a['columns']));
            foreach (array_slice($a['rows'] ?? [], 0, 200) as $r) {
                $lines[] = '| '.implode(' | ', array_map(fn ($c) => str_replace('|', '/', (string) ($r[$c] ?? '')), $a['columns'])).' |';
            }
        }
        $body = implode("\n", $lines);

        // Filed as a farm record in Document Management (and approved there like any other).
        return app(FarmDocuments::class)->file($farm, ['document_type' => 'report', 'title' => mb_strimwidth($d->title, 0, 150), 'issued_on' => today()->toDateString()],
            ['original_name' => \Illuminate\Support\Str::slug($d->title).'.txt', 'mime' => 'text/plain', 'data' => base64_encode($body)], $actor)->id;
    }
}
