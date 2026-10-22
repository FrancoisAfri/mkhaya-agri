<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\GrainSettlement;
use App\Modules\Agri\Models\GrainSettlementLine;
use App\Modules\Agri\Models\GrainTicket;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The buyer's side of every load: its delivery receipts and settlement statement, captured by hand
 * or imported from the elevator's CSV, matched line by line to our load tickets, the contract's
 * balance, and the settlement value posted.
 *
 * Matching: by our ticket number or the buyer's receipt number when the statement carries one,
 * else by the vehicle, the day (±1) and the net mass (within 2%). A matched line whose net mass,
 * grade, moisture, settled mass or amount is not what our ticket and contract say is "differs", with
 * each difference named; a load already settled on another line is "duplicate"; a line with no
 * load of ours is "unmatched". Our deliveries the statement should have had and does not are the
 * reconciliation's "no receipt".
 *
 * Posting goes the way every farm sale goes: AgriLedger::sale — Dr debtors / Cr produce sales,
 * with the farm, field and crop dimensions — one journal per crop the statement's loads came from,
 * the buyer's charges taken off pro rata. There is no grain ledger.
 */
class GrainSettlements
{
    /** CSV header (lower-case, letters and digits only) => line field. */
    public const ALIASES = [
        'buyer_ticket_ref' => ['ticket', 'ticketno', 'ticketnumber', 'receipt', 'receiptno', 'receiptnumber', 'deliveryno', 'deliverynote', 'grn', 'slip', 'slipno', 'weighbridgeticket'],
        'our_ticket_ref' => ['ourticket', 'farmticket', 'yourref', 'yourreference', 'growerref', 'clientref', 'producerref', 'reference', 'ref'],
        'vehicle_reg' => ['vehicle', 'vehiclereg', 'truck', 'truckreg', 'reg', 'registration', 'horse'],
        'delivered_on' => ['date', 'deliverydate', 'delivered', 'intakedate', 'receiveddate'],
        'gross_kg' => ['gross', 'grosskg', 'grossmass'],
        'tare_kg' => ['tare', 'tarekg', 'taremass'],
        'net_kg' => ['net', 'netkg', 'nett', 'nettkg', 'netmass', 'nettmass'],
        'moisture_pct' => ['moisture', 'moist', 'moisturepct', 'h2o', 'moisture%'],
        'foreign_matter_pct' => ['fm', 'foreignmatter', 'foreignmatterpct', 'screenings', 'scr'],
        'grade' => ['grade', 'class'],
        'deductions_kg' => ['deductions', 'deductionskg', 'deduct', 'shrink', 'shrinkkg'],
        'settled_kg' => ['settled', 'settledkg', 'payable', 'payablekg', 'nettpayable', 'netpayable', 'paymass', 'nettpaid', 'paidmass'],
        'price_per_t' => ['price', 'pricet', 'priceperton', 'pricepert', 'rate', 'ratet'],
        'amount' => ['amount', 'value', 'total', 'nettvalue', 'netvalue'],
    ];

    private const MASS = ['gross_kg', 'tare_kg', 'net_kg', 'deductions_kg', 'settled_kg'];

    /** A statement typed in from the buyer's paper, matched at once. */
    public function capture(int $companyId, array $data, array $lines, User $actor, array $file = []): GrainSettlement
    {
        $party = FinParty::withoutGlobalScopes()->where('company_id', $companyId)->find($data['party_id'] ?? 0);
        if (! $party) {
            throw ValidationException::withMessages(['party_id' => 'Choose the buyer whose statement it is.']);
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'A statement has at least one delivery on it.']);
        }
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference === '') {
            throw ValidationException::withMessages(['reference' => 'The statement number, as the buyer wrote it.']);
        }
        if (GrainSettlement::withoutGlobalScopes()->where('company_id', $companyId)->where('party_id', $party->id)->where('reference', $reference)->exists()) {
            throw ValidationException::withMessages(['reference' => "{$party->name}'s statement {$reference} is already captured."]);
        }
        $contract = ! empty($data['contract_id']) ? SalesContract::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(is_numeric($data['contract_id']) ? 'id' : 'uuid', $data['contract_id'])->first() : null;
        if ($contract && (int) $contract->party_id !== (int) $party->id) {
            throw ValidationException::withMessages(['contract_id' => "{$contract->reference} is with another buyer."]);
        }
        $dates = collect($lines)->pluck('delivered_on')->filter()->map(fn ($d) => Carbon::parse($d)->toDateString());

        return DB::transaction(function () use ($companyId, $data, $lines, $actor, $file, $party, $reference, $contract, $dates) {
            $s = GrainSettlement::create(['company_id' => $companyId, 'farm_id' => $data['farm_id'] ?? null, 'party_id' => $party->id, 'contract_id' => $contract?->id,
                'reference' => mb_substr($reference, 0, 60), 'statement_date' => $data['statement_date'] ?? today()->toDateString(),
                'period_from' => $data['period_from'] ?? $dates->min(), 'period_to' => $data['period_to'] ?? $dates->max(),
                'source' => $file ? 'csv' : 'manual', 'file_name' => $file['name'] ?? null, 'file_mime' => $file['mime'] ?? null, 'file_data' => $file['data'] ?? null,
                'charges' => (float) ($data['charges'] ?? 0), 'charges_note' => $data['charges_note'] ?? null, 'status' => 'draft', 'created_by' => $actor->id]);
            foreach (array_values($lines) as $i => $l) {
                GrainSettlementLine::create(['company_id' => $companyId, 'settlement_id' => $s->id, 'line_no' => $i + 1, 'vehicle_reg' => GrainLoads::reg($l['vehicle_reg'] ?? null)]
                    + array_filter(array_intersect_key($l, array_flip(['buyer_ticket_ref', 'our_ticket_ref', 'delivered_on', 'gross_kg', 'tare_kg', 'net_kg', 'moisture_pct',
                        'foreign_matter_pct', 'grade', 'deductions_kg', 'settled_kg', 'price_per_t', 'amount'])), fn ($v) => $v !== null && $v !== ''));
            }

            return $this->match($s->fresh());
        });
    }

    /** The elevator's CSV: columns found by their usual headings; masses in kg unless told they are tonnes. */
    public function import(int $companyId, array $data, UploadedFile $csv, User $actor): GrainSettlement
    {
        $body = (string) file_get_contents($csv->getRealPath());
        if (strlen($body) > 2_000_000) {
            throw ValidationException::withMessages(['file' => 'The statement file is larger than 2 MB.']);
        }
        $lines = $this->parse($body, ($data['mass_unit'] ?? 'kg') === 't');
        if (! $lines) {
            throw ValidationException::withMessages(['file' => 'No deliveries found: the file needs a header row with at least a net or settled mass column.']);
        }

        return $this->capture($companyId, $data, $lines, $actor, ['name' => mb_strimwidth($csv->getClientOriginalName(), 0, 200), 'mime' => $csv->getMimeType() ?: 'text/csv', 'data' => base64_encode($body)]);
    }

    /** @return list<array> statement lines from a CSV body */
    public function parse(string $body, bool $tonnes = false): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $rows = preg_split('/\r\n|\n|\r/', trim($body)) ?: [];
        if (count($rows) < 2) {
            return [];
        }
        $delimiter = substr_count($rows[0], ';') > substr_count($rows[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => preg_replace('/[^a-z0-9%]/', '', mb_strtolower(trim((string) $h))), str_getcsv(array_shift($rows), $delimiter));
        $map = [];
        foreach ($header as $i => $h) {
            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($map[$field]) && in_array($h, $aliases, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($map['net_kg']) && ! isset($map['settled_kg'])) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (trim($row) === '') {
                continue;
            }
            $cells = str_getcsv($row, $delimiter);
            $l = [];
            foreach ($map as $field => $i) {
                $v = trim((string) ($cells[$i] ?? ''));
                if ($v === '') {
                    continue;
                }
                if (in_array($field, [...self::MASS, 'moisture_pct', 'foreign_matter_pct', 'price_per_t', 'amount'], true)) {
                    $n = str_replace([' ', 'R', '%'], '', $v);
                    $n = preg_match('/^-?\d+,\d+$/', $n) ? str_replace(',', '.', $n) : str_replace(',', '', $n);
                    if (! is_numeric($n)) {
                        continue;
                    }
                    $v = (float) $n * ($tonnes && in_array($field, self::MASS, true) ? 1000 : 1);
                } elseif ($field === 'delivered_on') {
                    $v = preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : (strtotime($v) ? date('Y-m-d', strtotime($v)) : null);
                } else {
                    $v = mb_substr($v, 0, $field === 'grade' ? 16 : 60);
                }
                if ($v !== null) {
                    $l[$field] = $v;
                }
            }
            if (isset($l['net_kg']) || isset($l['settled_kg'])) {
                $out[] = $l;
            }
        }

        return $out;
    }

    /**
     * Match every line to a load of ours, again from the start (a re-match after a correction gives
     * the same answer). Our tickets are released from the statement's lines first.
     */
    public function match(GrainSettlement $s): GrainSettlement
    {
        if ($s->status === 'posted') {
            throw ValidationException::withMessages(['settlement' => "Statement {$s->reference} is posted."]);
        }
        $lines = $s->lines()->get();

        return DB::transaction(function () use ($s, $lines) {
            GrainTicket::withoutGlobalScopes()->where('company_id', $s->company_id)->whereIn('settlement_line_id', $lines->pluck('id'))->update(['settlement_line_id' => null]);
            $candidates = GrainTicket::withoutGlobalScopes()->where('company_id', $s->company_id)->where('party_id', $s->party_id)->where('status', 'complete')
                ->whereIn('movement', GrainTicket::TO_BUYER)->get();
            $taken = [];
            foreach ($lines as $l) {
                $t = $this->find($l, $candidates, $taken);
                if (! $t) {
                    $l->update(['ticket_id' => null, 'match_status' => 'unmatched', 'differences' => null, 'expected_amount' => $this->expected($s, null, $l)]);
                    continue;
                }
                if (in_array($t->id, $taken, true) || $t->settlement_line_id) {
                    $l->update(['ticket_id' => $t->id, 'match_status' => 'duplicate', 'expected_amount' => null,
                        'differences' => [['field' => 'ticket', 'ours' => $t->number, 'theirs' => $l->buyer_ticket_ref, 'message' => "Load {$t->number} is already settled on another line."]]]);
                    continue;
                }
                $expected = $this->expected($s, $t, $l);
                $diffs = $this->differences($t, $l, $expected);
                $l->update(['ticket_id' => $t->id, 'match_status' => $diffs ? 'differs' : 'matched', 'differences' => $diffs ?: null, 'expected_amount' => $expected]);
                $t->forceFill(['settlement_line_id' => $l->id, 'buyer_ticket_ref' => $t->buyer_ticket_ref ?: $l->buyer_ticket_ref])->save();
                $taken[] = $t->id;
                $candidates = $candidates->map(fn ($c) => $c->id === $t->id ? $t : $c);
            }
            $s->update(['status' => 'matched', 'matched_at' => now(), 'totals' => $this->totals($s->fresh())]);

            return $s->fresh();
        });
    }

    private function find(GrainSettlementLine $l, $candidates, array $taken): ?GrainTicket
    {
        foreach (array_filter([$l->our_ticket_ref, $l->buyer_ticket_ref]) as $ref) {
            $hit = $candidates->first(fn ($t) => strcasecmp($t->number, $ref) === 0 || ($t->buyer_ticket_ref && strcasecmp($t->buyer_ticket_ref, $ref) === 0));
            if ($hit) {
                return $hit;
            }
        }
        $net = $l->net_kg ?? $l->settled_kg;
        if (! $net) {
            return null;
        }
        $day = $l->delivered_on?->toDateString();
        $pool = $candidates->filter(fn ($t) => ! in_array($t->id, $taken, true) && ! $t->settlement_line_id
            && (! $l->vehicle_reg || ! $t->vehicle_reg || $t->vehicle_reg === $l->vehicle_reg)
            && (! $day || abs(Carbon::parse($t->loadDate())->diffInDays(Carbon::parse($day), false)) <= 1)
            && abs((float) ($l->net_kg !== null ? $t->net_kg : $t->settled_kg) - (float) $net) <= max(50, 0.02 * (float) $net));

        return $pool->sortBy(fn ($t) => abs((float) ($l->net_kg !== null ? $t->net_kg : $t->settled_kg) - (float) $net))->first();
    }

    /** What our contract says the line is worth: its price for the line's grade on the day, per tonne, times the mass the buyer settled. */
    private function expected(GrainSettlement $s, ?GrainTicket $t, GrainSettlementLine $l): ?float
    {
        $contract = SalesContract::withoutGlobalScopes()->find($t?->contract_id ?? $s->contract_id);
        $settled = $l->settled_kg ?? $t?->settled_kg;
        if (! $contract || ! $settled) {
            return null;
        }
        $p = app(FarmSales::class)->contractPrice($contract, $l->grade ?? $t?->grade, ($l->delivered_on?->toDateString() ?? $t?->loadDate()));
        $perT = $p['price'] !== null ? MarketData::convertPrice($p['price'], $contract->unit, 't') : null;

        return $perT !== null ? round($perT * $settled / 1000, 2) : null;
    }

    /** @return list<array{field: string, ours: mixed, theirs: mixed, message: string}> */
    private function differences(GrainTicket $t, GrainSettlementLine $l, ?float $expected): array
    {
        $d = [];
        $kg = fn ($v) => number_format((float) $v, 0, '.', ' ').' kg';
        if ($l->net_kg !== null && abs($l->net_kg - (float) $t->net_kg) > max(20, 0.0025 * (float) $t->net_kg)) {
            $d[] = ['field' => 'net_kg', 'ours' => $t->net_kg, 'theirs' => $l->net_kg, 'message' => "Net mass: ours {$kg($t->net_kg)}, theirs {$kg($l->net_kg)} (".sprintf('%+.0f', $l->net_kg - $t->net_kg).' kg).'];
        }
        if ($l->grade && $t->grade && strcasecmp($l->grade, $t->grade) !== 0) {
            $d[] = ['field' => 'grade', 'ours' => $t->grade, 'theirs' => $l->grade, 'message' => "Graded {$l->grade} by the buyer; {$t->grade} on our ticket."];
        }
        if ($l->moisture_pct !== null && $t->moisture_pct !== null && abs($l->moisture_pct - $t->moisture_pct) > 0.5) {
            $d[] = ['field' => 'moisture_pct', 'ours' => $t->moisture_pct, 'theirs' => $l->moisture_pct, 'message' => "Moisture: ours {$t->moisture_pct}%, theirs {$l->moisture_pct}%."];
        }
        if ($l->settled_kg !== null && $t->settled_kg !== null && abs($l->settled_kg - $t->settled_kg) > max(20, 0.005 * $t->settled_kg)) {
            $d[] = ['field' => 'settled_kg', 'ours' => $t->settled_kg, 'theirs' => $l->settled_kg, 'message' => "Settled mass: ours {$kg($t->settled_kg)}, theirs {$kg($l->settled_kg)}."];
        }
        $amount = $this->amountOf($l);
        if ($expected !== null && $amount !== null && abs($amount - $expected) > max(1, 0.005 * $expected)) {
            $d[] = ['field' => 'amount', 'ours' => $expected, 'theirs' => $amount, 'message' => 'Paid R '.number_format($amount, 2, '.', ' ').'; the contract price gives R '.number_format($expected, 2, '.', ' ').'.'];
        }

        return $d;
    }

    /** What the buyer pays for a line: its amount, else its settled mass at its price per tonne. */
    public function amountOf(GrainSettlementLine $l): ?float
    {
        if ($l->amount !== null) {
            return (float) $l->amount;
        }

        return $l->settled_kg !== null && $l->price_per_t !== null ? round($l->settled_kg / 1000 * $l->price_per_t, 2) : null;
    }

    private function totals(GrainSettlement $s): array
    {
        $lines = $s->lines()->get();
        $gross = (float) $lines->sum(fn ($l) => $this->amountOf($l) ?? 0);

        return ['lines' => $lines->count(), 'matched' => $lines->where('match_status', 'matched')->count(), 'differs' => $lines->where('match_status', 'differs')->count(),
            'unmatched' => $lines->where('match_status', 'unmatched')->count(), 'duplicate' => $lines->where('match_status', 'duplicate')->count(),
            'net_t' => round((float) $lines->sum('net_kg') / 1000, 3), 'settled_t' => round((float) $lines->sum('settled_kg') / 1000, 3),
            'gross_value' => round($gross, 2), 'charges' => round((float) $s->charges, 2), 'net_value' => round($gross - (float) $s->charges, 2),
            'expected_value' => round((float) $lines->sum('expected_amount'), 2)];
    }

    /**
     * Post what the buyer pays: one sale per crop the loads came from (a line with no load of ours
     * goes to the statement's farm), the buyer's charges taken off pro rata, through AgriLedger::sale
     * as every farm sale is. A line matched to a load already settled elsewhere must be sorted out
     * with the buyer first.
     */
    public function post(GrainSettlement $s, User $actor): GrainSettlement
    {
        if ($s->status === 'posted') {
            throw ValidationException::withMessages(['settlement' => "Statement {$s->reference} is already posted."]);
        }
        if ($s->status !== 'matched') {
            throw ValidationException::withMessages(['settlement' => 'Match the statement to the loads before posting it.']);
        }
        $lines = $s->lines()->get();
        if ($lines->contains('match_status', 'duplicate')) {
            throw ValidationException::withMessages(['settlement' => 'A line claims a load another line already settled: sort it out with the buyer, then match again.']);
        }
        if ($lines->contains(fn ($l) => $this->amountOf($l) === null)) {
            throw ValidationException::withMessages(['settlement' => 'Every line needs what the buyer pays for it (an amount, or a price per tonne).']);
        }
        $tickets = GrainTicket::withoutGlobalScopes()->whereIn('id', $lines->pluck('ticket_id')->filter())->get()->keyBy('id');
        $groups = [];
        foreach ($lines as $l) {
            $t = $l->ticket_id ? $tickets[$l->ticket_id] ?? null : null;
            $farmId = $t?->farm_id ?? $s->farm_id;
            if (! $farmId) {
                throw ValidationException::withMessages(['farm_id' => "Line {$l->line_no} has no load of ours: say which farm the statement's other deliveries are from."]);
            }
            $key = $farmId.':'.($t?->crop_plan_id ?? 0);
            $groups[$key] ??= ['farm_id' => $farmId, 'plan_id' => $t?->crop_plan_id, 'amount' => 0.0];
            $groups[$key]['amount'] += $this->amountOf($l);
        }
        $gross = array_sum(array_column($groups, 'amount'));
        if ($gross <= 0) {
            throw ValidationException::withMessages(['settlement' => 'The statement pays nothing.']);
        }
        $buyer = FinParty::withoutGlobalScopes()->find($s->party_id)?->name;
        $ledger = app(AgriLedger::class);

        try {
            return DB::transaction(function () use ($s, $actor, $groups, $gross, $buyer, $ledger) {
                $journals = [];
                foreach ($groups as $key => $g) {
                    $net = round($g['amount'] - (float) $s->charges * $g['amount'] / $gross, 2);
                    if ($net <= 0) {
                        continue;
                    }
                    $farm = Farm::withoutGlobalScope('farm_access')->findOrFail($g['farm_id']);
                    $plan = $g['plan_id'] ? CropPlan::withoutGlobalScope('farm_access')->find($g['plan_id']) : null;
                    $field = $plan?->field_id ? FarmField::withoutGlobalScope('farm_access')->find($plan->field_id) : null;
                    $journals[] = $ledger->sale($farm, $net, $s->statement_date->toDateString(), mb_strimwidth("Grain settlement {$s->reference} — {$buyer}".($plan ? " ({$plan->code})" : ''), 0, 250),
                        AgriLedger::dimensions($farm, $field, $plan, 'produce'), 'produce', 'receivable', "agri-grain-settlement-{$s->uuid}-{$key}")->id;
                }
                $s->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $actor->id, 'journal_ids' => $journals, 'totals' => $this->totals($s)]);

                return $s->fresh();
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['settlement' => $e->getMessage()]);
        }
    }

    /** The mass delivered on a contract by load, in the contract's unit: what the buyer settled where it has, else our settled mass. */
    public static function loadsOn(SalesContract $c): float
    {
        $tickets = GrainTicket::withoutGlobalScopes()->where('company_id', $c->company_id)->where('contract_id', $c->id)->where('status', 'complete')->get();
        if ($tickets->isEmpty()) {
            return 0.0;
        }
        $theirs = GrainSettlementLine::withoutGlobalScopes()->whereIn('id', $tickets->pluck('settlement_line_id')->filter())->pluck('settled_kg', 'id');
        $kg = $tickets->sum(fn ($t) => (float) ($t->settlement_line_id && isset($theirs[$t->settlement_line_id]) ? $theirs[$t->settlement_line_id] : $t->settled_kg));

        return MarketData::fromKg($kg, $c->unit) ?? 0.0;
    }

    /** A contract by load: contracted, delivered (settled by the buyer or, until then, by us), accepted, paid, outstanding. */
    public function contractBalance(SalesContract $c): array
    {
        $tickets = GrainTicket::query()->where('contract_id', $c->id)->where('status', 'complete')->get();
        $lines = GrainSettlementLine::query()->whereIn('id', $tickets->pluck('settlement_line_id')->filter())->get();
        $pos = app(FarmSales::class)->position($c);
        $unit = $c->unit;
        $u = fn ($kg) => MarketData::fromKg((float) $kg, $unit) ?? 0.0;
        $delivered = (float) $pos['delivered'];
        $tolerance = (float) $c->quantity * (float) $c->tolerance_pct / 100;

        return ['reference' => $c->reference, 'id' => $c->uuid, 'buyer' => FinParty::withoutGlobalScopes()->find($c->party_id)?->name, 'product' => $c->product, 'unit' => $unit,
            'pricing' => $c->pricing, 'price_now' => app(FarmSales::class)->contractPrice($c), 'status' => $c->status,
            'contracted' => (float) $c->quantity, 'tolerance' => round($tolerance, 3), 'loads' => $tickets->count(),
            'delivered' => round($delivered, 3), 'delivered_by_load' => round((float) $pos['loads'], 3), 'our_settled' => round($u($tickets->sum('settled_kg')), 3),
            'buyer_accepted' => round($u($lines->sum('settled_kg')), 3), 'awaiting_receipt' => $tickets->whereNull('settlement_line_id')->count(),
            'paid_value' => round((float) $lines->sum(fn ($l) => $this->amountOf($l) ?? 0), 2),
            'outstanding' => round(max(0, (float) $c->quantity - $delivered), 3),
            'state' => $delivered > (float) $c->quantity + $tolerance ? 'over' : ($delivered + $tolerance >= (float) $c->quantity ? 'filled' : 'open')];
    }

    public function row(GrainSettlement $s): array
    {
        return $s->only(['uuid', 'reference', 'source', 'file_name', 'charges', 'charges_note', 'status', 'totals', 'journal_ids'])
            + ['statement_date' => $s->statement_date?->toDateString(), 'period_from' => $s->period_from?->toDateString(), 'period_to' => $s->period_to?->toDateString(),
                'buyer' => FinParty::withoutGlobalScopes()->find($s->party_id)?->name, 'contract' => $s->contract_id ? SalesContract::withoutGlobalScopes()->find($s->contract_id)?->reference : null,
                'lines' => $s->lines()->get()->map(fn ($l) => $l->only(['line_no', 'buyer_ticket_ref', 'our_ticket_ref', 'vehicle_reg', 'gross_kg', 'tare_kg', 'net_kg', 'moisture_pct',
                    'foreign_matter_pct', 'grade', 'deductions_kg', 'settled_kg', 'price_per_t', 'amount', 'expected_amount', 'match_status', 'differences'])
                    + ['delivered_on' => $l->delivered_on?->toDateString(), 'ticket' => $l->ticket_id ? GrainTicket::withoutGlobalScopes()->find($l->ticket_id)?->number : null])->values()->all()];
    }
}
