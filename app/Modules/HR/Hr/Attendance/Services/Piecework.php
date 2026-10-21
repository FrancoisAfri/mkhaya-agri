<?php

namespace App\Modules\HR\Hr\Attendance\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Model;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Attendance\Models\PieceRate;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\HR\Hr\Attendance\Models\PieceworkShare;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production-based pay (Phase 2 §1.5): kilograms picked, crates packed, rows weeded, trees pruned.
 *
 *  - A rate is used only once approved, and only between its dates.
 *  - What a worker or crew submits is the measured quantity; a supervisor approves the quantity that
 *    counts. Rejected and reworked quantities are recorded and never paid (LAB-008).
 *  - Earnings are the approved quantity at the approved rate, frozen at approval, so a later rate
 *    change never re-prices work already approved (LAB-007).
 *  - A crew's earnings are shared equally, by agreed percentages, or by each person's recorded
 *    output, and the shares always add up to the whole, to the cent.
 */
class Piecework
{
    public function approveRate(PieceRate $rate, User $actor): PieceRate
    {
        if ($rate->rate <= 0) {
            throw ValidationException::withMessages(['rate' => 'A piece rate must be more than zero.']);
        }
        $rate->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

        return $rate;
    }

    /** The approved rate for an activity and unit on a date, most specific first (farm, crop). */
    public function rateFor(int $companyId, ?int $farmId, string $activity, string $unit, Carbon $on, ?int $cropTypeId = null): ?PieceRate
    {
        return PieceRate::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'approved')
            ->where('activity', $activity)->where('unit', $unit)
            ->whereDate('valid_from', '<=', $on)->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on))
            ->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $farmId))
            ->where(fn ($q) => $q->whereNull('crop_type_id')->orWhere('crop_type_id', $cropTypeId))
            ->orderByRaw('farm_id IS NULL')->orderByRaw('crop_type_id IS NULL')->orderByDesc('valid_from')->first();
    }

    /**
     * @param  array{work_date: string, activity: string, unit: string, measured_quantity: float, worker_id?: int, crew_id?: int,
     *               allocation?: string, field_id?: int, crop_plan_id?: int, work_order_id?: int, client_id?: string,
     *               shares?: list<array{worker_id: int, percent?: float, quantity?: float}>}  $data
     */
    public function submit(?Model $site, array $data, User $actor): PieceworkEntry
    {
        if (! empty($data['client_id'])) {
            $seen = PieceworkEntry::withoutGlobalScopes()->where('company_id', $site?->company_id ?? $actor->company_id)->where('client_id', $data['client_id'])->first();
            if ($seen) {
                return $seen;
            }
        }
        if (empty($data['worker_id']) === empty($data['crew_id'])) {
            throw ValidationException::withMessages(['worker_id' => 'Record the work against one worker or one crew.']);
        }
        if ((float) $data['measured_quantity'] <= 0) {
            throw ValidationException::withMessages(['measured_quantity' => 'Quantity must be more than zero.']);
        }
        if (Carbon::parse($data['work_date'])->isAfter(today())) {
            throw ValidationException::withMessages(['work_date' => 'Work cannot be recorded for a future date.']);
        }
        $allocation = ! empty($data['worker_id']) ? 'individual' : ($data['allocation'] ?? 'equal');
        if (! in_array($allocation, PieceworkEntry::ALLOCATIONS, true)) {
            throw ValidationException::withMessages(['allocation' => 'Unknown allocation.']);
        }

        return DB::transaction(function () use ($site, $data, $actor, $allocation) {
            $entry = PieceworkEntry::create([
                'company_id' => $site?->company_id ?? $actor->company_id, 'farm_id' => $site?->id, 'work_date' => $data['work_date'],
                'activity' => $data['activity'], 'unit' => $data['unit'], 'worker_id' => $data['worker_id'] ?? null,
                'crew_id' => $data['crew_id'] ?? null, 'allocation' => $allocation, 'field_id' => $data['field_id'] ?? null,
                'crop_plan_id' => $data['crop_plan_id'] ?? null, 'work_order_id' => $data['work_order_id'] ?? null,
                'measured_quantity' => $data['measured_quantity'], 'status' => 'submitted', 'client_id' => $data['client_id'] ?? null,
                'submitted_by' => $actor->id,
            ]);
            if ($entry->crew_id) {
                $members = Worker::query()->where('crew_id', $entry->crew_id)->where('status', Worker::ACTIVE)->pluck('id');
                $given = collect($data['shares'] ?? [])->keyBy('worker_id');
                if (in_array($allocation, ['percentage', 'recorded'], true)) {
                    if ($given->isEmpty() || $given->keys()->diff($members)->isNotEmpty()) {
                        throw ValidationException::withMessages(['shares' => 'Give each crew member\'s share; only crew members can share in crew work.']);
                    }
                    if ($allocation === 'percentage' && abs($given->sum('percent') - 100) > 0.01) {
                        throw ValidationException::withMessages(['shares' => 'Percentages must add up to 100.']);
                    }
                    if ($allocation === 'recorded' && abs($given->sum('quantity') - (float) $entry->measured_quantity) > 0.001) {
                        throw ValidationException::withMessages(['shares' => 'Individual quantities must add up to the crew total.']);
                    }
                }
                $ids = in_array($allocation, ['percentage', 'recorded'], true) ? $given->keys() : $members;
                if ($ids->isEmpty()) {
                    throw ValidationException::withMessages(['crew_id' => 'This crew has no active members.']);
                }
                foreach ($ids as $wid) {
                    PieceworkShare::create(['company_id' => $entry->company_id, 'farm_id' => $entry->farm_id, 'entry_id' => $entry->id, 'worker_id' => $wid,
                        'percent' => $given[$wid]['percent'] ?? null, 'quantity' => $given[$wid]['quantity'] ?? null]);
                }
            }

            return $entry;
        });
    }

    /**
     * Approve the verified quantity. Anything not approved is recorded as rejected (or rework) and
     * earns nothing. The rate is the approved one on the work date, frozen here.
     */
    public function approve(PieceworkEntry $entry, float $approvedQuantity, float $rework, ?string $note, User $actor): PieceworkEntry
    {
        if (! in_array($entry->status, ['submitted', 'queried'], true)) {
            throw ValidationException::withMessages(['status' => "This entry is {$entry->status}."]);
        }
        if ((int) $entry->submitted_by === (int) $actor->id && $entry->worker_id) {
            $w = Worker::withoutGlobalScopes()->with('person')->find($entry->worker_id);
            if ($w && (int) $w->person?->user_id === (int) $actor->id) {
                throw ValidationException::withMessages(['status' => 'You cannot approve your own production.']);
            }
        }
        if ($approvedQuantity < 0 || $rework < 0 || $approvedQuantity + $rework > (float) $entry->measured_quantity + 0.0005) {
            throw ValidationException::withMessages(['approved_quantity' => 'Approved and rework quantities cannot exceed what was measured ('.$entry->measured_quantity.').']);
        }
        $rate = $this->rateFor($entry->company_id, $entry->farm_id, $entry->activity, $entry->unit, $entry->work_date,
            $entry->crop_plan_id ? \App\Modules\Agri\Models\CropPlan::withoutGlobalScopes()->whereKey($entry->crop_plan_id)->value('crop_type_id') : null);
        if (! $rate) {
            throw ValidationException::withMessages(['rate' => "There is no approved {$entry->activity} rate per {$entry->unit} for {$entry->work_date->toDateString()}."]);
        }
        $amount = round($approvedQuantity * $rate->rate, 2);

        return DB::transaction(function () use ($entry, $approvedQuantity, $rework, $note, $actor, $rate, $amount) {
            $entry->update([
                'approved_quantity' => $approvedQuantity, 'rework_quantity' => $rework,
                'rejected_quantity' => round((float) $entry->measured_quantity - $approvedQuantity - $rework, 3),
                'piece_rate_id' => $rate->id, 'rate' => $rate->rate, 'amount' => $amount, 'status' => 'approved',
                'review_note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ]);
            if ($entry->crew_id) {
                $this->share($entry, $amount);
            }

            return $entry;
        });
    }

    public function reject(PieceworkEntry $entry, string $note, User $actor): PieceworkEntry
    {
        $this->assertOpen($entry);
        $entry->update(['status' => 'rejected', 'approved_quantity' => 0, 'rejected_quantity' => $entry->measured_quantity, 'amount' => 0,
            'review_note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

        return $entry;
    }

    public function query(PieceworkEntry $entry, string $note, User $actor): PieceworkEntry
    {
        $this->assertOpen($entry);
        $entry->update(['status' => 'queried', 'review_note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

        return $entry;
    }

    /** Split a crew's earnings; the last share takes the rounding so the parts equal the whole. */
    private function share(PieceworkEntry $entry, float $amount): void
    {
        $shares = $entry->shares()->get();
        $n = $shares->count();
        $allocated = 0.0;
        foreach ($shares->values() as $i => $s) {
            $part = match ($entry->allocation) {
                'percentage' => $amount * ((float) $s->percent / 100),
                'recorded' => $amount * ((float) $s->quantity / max(0.0001, (float) $entry->measured_quantity)),
                default => $amount / $n,
            };
            $part = $i === $n - 1 ? round($amount - $allocated, 2) : round($part, 2);
            $allocated += $part;
            $s->update(['amount' => $part]);
        }
    }

    private function assertOpen(PieceworkEntry $entry): void
    {
        if (! in_array($entry->status, ['submitted', 'queried'], true)) {
            throw ValidationException::withMessages(['status' => "This entry is {$entry->status}."]);
        }
    }

    /** Output against target, earnings and cost per unit for a period (§1.5 reporting). */
    public function summary(int $farmId, Carbon $from, Carbon $to): array
    {
        $entries = PieceworkEntry::query()->where('farm_id', $farmId)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();
        $approved = $entries->where('status', 'approved');

        return $approved->groupBy(fn ($e) => $e->activity.'|'.$e->unit)->map(function ($g, $k) {
            [$activity, $unit] = explode('|', $k);
            $qty = (float) $g->sum('approved_quantity');
            $amt = (float) $g->sum('amount');

            return ['activity' => $activity, 'unit' => $unit, 'measured' => round((float) $g->sum('measured_quantity'), 3), 'approved' => round($qty, 3),
                'rejected' => round((float) $g->sum('rejected_quantity'), 3), 'rework' => round((float) $g->sum('rework_quantity'), 3),
                'earnings' => round($amt, 2), 'cost_per_unit' => $qty > 0 ? round($amt / $qty, 4) : null];
        })->values()->all();
    }
}
