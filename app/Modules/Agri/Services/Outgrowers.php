<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\Grower;
use App\Modules\Agri\Models\GrowerAdvance;
use App\Modules\Agri\Models\GrowerCollection;
use App\Modules\Agri\Models\GrowerContract;
use App\Modules\Agri\Models\GrowerInspection;
use App\Modules\Agri\Models\GrowerSettlement;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Shared\Party\PartyDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §16D: cooperative and outgrower management.
 *
 * Growers are suppliers in Finance. A contract (approved by someone other than who drafted it)
 * sets what each grower grows, how much is expected and the price per grade. Inputs issued to a
 * grower leave the store into Grower Input Advances, within the contract's limit. Inspections
 * record the crop and the inspector's estimate, which roll up into the forecast. Collections are
 * graded and weighed in, received into the store against goods-received-not-invoiced, and become
 * lots traced back to the grower. Settlement bills the grower for what was collected and recovers
 * the outstanding advances against that bill, so the grower is paid the net and every rand is in
 * the books once.
 */
class Outgrowers
{
    public function __construct(private readonly StockLedger $stock, private readonly Ledger $ledger) {}

    public function register(Farm $farm, array $data, User $actor): Grower
    {
        $party = ! empty($data['party_id']) ? $data['party_id'] : app(PartyDirectory::class)->create(array_filter(['company_id' => $farm->company_id, 'kind' => 'supplier',
            'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'bank_name' => $data['bank_name'] ?? null, 'bank_account' => $data['bank_account'] ?? null,
            'credit_terms_days' => 7, 'category' => 'agri_grower'], fn ($v) => $v !== null), 'agri')->id;

        return Grower::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'party_id' => $party, 'code' => $data['code'], 'location' => $data['location'] ?? null,
            'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'hectares' => $data['hectares'] ?? null]);
    }

    public function contract(Grower $g, array $data, User $actor): GrowerContract
    {
        $n = GrowerContract::query()->where('company_id', $g->company_id)->count() + 1;

        return GrowerContract::create(['company_id' => $g->company_id, 'grower_id' => $g->id, 'reference' => sprintf('GC-%s-%04d', now()->format('y'), $n), 'status' => 'draft',
            'created_by' => $actor->id] + array_intersect_key($data, array_flip(['crop_type_id', 'product', 'hectares', 'expected_quantity', 'unit', 'price', 'grade_prices', 'advance_limit'])));
    }

    public function approve(GrowerContract $c, User $actor): GrowerContract
    {
        if ($c->status !== 'draft') {
            throw ValidationException::withMessages(['contract' => "That contract is {$c->status}."]);
        }
        if ((int) $c->created_by === (int) $actor->id) {
            throw ValidationException::withMessages(['contract' => 'Someone other than who drafted the grower contract approves it.']);
        }
        $c->update(['status' => 'active', 'approved_by' => $actor->id]);

        return $c;
    }

    /** Inputs (from a farm store) or cash advanced to a grower, within the contract's limit. */
    public function advance(GrowerContract $c, array $data, User $actor): GrowerAdvance
    {
        if ($c->status !== 'active') {
            throw ValidationException::withMessages(['contract' => 'Advances are made only on an approved contract.']);
        }
        $g = Grower::query()->findOrFail($c->grower_id);
        $farm = Farm::query()->findOrFail($g->farm_id);
        $out = (float) GrowerAdvance::query()->where('contract_id', $c->id)->get()->sum(fn ($a) => $a->value - $a->recovered);

        try {
            return DB::transaction(function () use ($c, $g, $farm, $data, $actor, $out) {
                $a = GrowerAdvance::create(['company_id' => $c->company_id, 'grower_id' => $g->id, 'contract_id' => $c->id, 'kind' => $data['kind'], 'issued_on' => $data['issued_on'] ?? today(),
                    'value' => 0, 'issued_by' => $actor->id]);
                $dims = AgriLedger::dimensions($farm, null, null, 'grower_advance') + ['agri_grower' => (string) $g->id];
                if ($data['kind'] === 'input') {
                    $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
                    $issued = app(FarmStores::class)->issue($farm, $item, (float) $data['quantity'], ['source_type' => 'agri_grower_advance', 'source_id' => $a->id,
                        'idempotency_key' => "agri-grower-adv-{$a->uuid}", 'reference' => "Advance to {$g->code}", 'expense_account' => config('agri.accounts.grower_advances'),
                        'reason_code' => 'AGRI_USE', 'actor' => $actor, 'dimensions' => $dims]);
                    $a->update(['stock_item_id' => $item->id, 'quantity' => $data['quantity'], 'value' => $issued['value'], 'stock_movement_id' => $issued['movement']->id]);
                } else {
                    $j = app(AgriLedger::class)->expense($farm, (float) $data['amount'], $a->issued_on->toDateString(), "Cash advance to {$g->code}", $dims, 'bank',
                        config('agri.accounts.grower_advances'), "agri-grower-cash-{$a->uuid}");
                    $a->update(['value' => $data['amount'], 'journal_id' => $j->id]);
                }
                if ($c->advance_limit > 0 && $out + $a->value > $c->advance_limit + 0.005) {
                    throw ValidationException::withMessages(['amount' => "This would take {$g->code}'s advances to R".number_format($out + $a->value, 2).', over the contract limit of R'.number_format($c->advance_limit, 2).'.']);
                }

                return $a->fresh();
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
    }

    public function inspect(GrowerContract $c, array $data, User $actor): GrowerInspection
    {
        return GrowerInspection::create(['company_id' => $c->company_id, 'grower_id' => $c->grower_id, 'contract_id' => $c->id, 'inspected_by' => $actor->id]
            + array_intersect_key($data, array_flip(['inspected_on', 'stage', 'estimated_quantity', 'outcome', 'notes'])));
    }

    /** What the growers are expected to deliver: the latest inspection estimate, else the contract, less what has come in. */
    public function forecast(int $farmId): array
    {
        return GrowerContract::query()->where('status', 'active')->whereIn('grower_id', Grower::query()->where('farm_id', $farmId)->pluck('id'))->get()->map(function ($c) {
            $est = GrowerInspection::query()->where('contract_id', $c->id)->whereNotNull('estimated_quantity')->latest('inspected_on')->first();
            $in = (float) GrowerCollection::query()->where('contract_id', $c->id)->sum(DB::raw('quantity - rejected'));
            $expected = $est?->estimated_quantity ?? $c->expected_quantity;

            return ['contract' => $c->reference, 'grower' => Grower::query()->whereKey($c->grower_id)->value('code'), 'expected' => $expected, 'basis' => $est ? 'inspection of '.$est->inspected_on->toDateString() : 'contract',
                'collected' => round($in, 3), 'to_come' => round(max(0, $expected - $in), 3), 'unit' => $c->unit];
        })->all();
    }

    /** Weighed and graded in: accepted produce into the store at the contract price, a lot traced to the grower. */
    public function collect(GrowerContract $c, FarmStore $store, FinStockItem $item, array $data, User $actor): GrowerCollection
    {
        if ($c->status !== 'active') {
            throw ValidationException::withMessages(['contract' => 'Collections are made only on an approved contract.']);
        }
        $g = Grower::query()->findOrFail($c->grower_id);
        if (! empty($data['client_id']) && ($done = GrowerCollection::query()->where('grower_id', $g->id)->where('client_id', $data['client_id'])->first())) {
            return $done;
        }
        $accepted = round((float) $data['quantity'] - (float) ($data['rejected'] ?? 0), 3);
        if ($accepted <= 0) {
            throw ValidationException::withMessages(['rejected' => 'Nothing was accepted.']);
        }
        $price = (float) (($c->grade_prices ?? [])[$data['grade'] ?? ''] ?? $c->price);

        return DB::transaction(function () use ($c, $g, $store, $item, $data, $actor, $accepted, $price) {
            $col = GrowerCollection::create(['company_id' => $c->company_id, 'grower_id' => $g->id, 'contract_id' => $c->id, 'collected_on' => $data['collected_on'] ?? today(),
                'quantity' => $data['quantity'], 'rejected' => $data['rejected'] ?? 0, 'grade' => $data['grade'] ?? null, 'price' => $price, 'value' => round($accepted * $price, 2),
                'client_id' => $data['client_id'] ?? null, 'recorded_by' => $actor->id]);
            $m = $this->stock->receive($item, $accepted, $price, FinWarehouse::withoutGlobalScopes()->findOrFail($store->warehouse_id), ['date' => $col->collected_on->toDateString(),
                'reference' => "Collection {$g->code} {$c->reference}", 'contra' => config('finance.grni_account', '2150'), 'source_type' => 'agri_grower_collection', 'source_id' => $col->id,
                'idempotency_key' => "agri-grower-col-{$col->uuid}", 'reason_code' => 'PURCHASE', 'dimensions' => AgriLedger::dimensions(Farm::query()->findOrFail($g->farm_id), null, null, 'stock') + ['agri_grower' => (string) $g->id]]);
            $lot = ProduceLot::create(['company_id' => $c->company_id, 'farm_id' => $g->farm_id, 'code' => "{$g->code}-".$col->collected_on->format('Ymd')."-{$col->id}", 'kind' => 'collected',
                'product' => $c->product, 'crop_type_id' => $c->crop_type_id, 'grade' => $data['grade'] ?? null, 'quantity' => $accepted, 'remaining' => $accepted, 'unit' => $c->unit,
                'stock_item_id' => $item->id, 'store_id' => $store->id, 'warehouse_id' => $store->warehouse_id, 'unit_cost' => $price, 'status' => 'available',
                'produced_on' => $col->collected_on, 'location' => $data['location'] ?? null, 'created_by' => $actor->id]);
            $col->update(['lot_id' => $lot->id, 'stock_movement_id' => $m->id]);

            return $col;
        });
    }

    /** A draft settlement: collections not yet settled, less what is outstanding on advances. */
    public function draftSettlement(Grower $g, User $actor): GrowerSettlement
    {
        $cols = GrowerCollection::query()->where('grower_id', $g->id)->whereNull('settlement_id')->get();
        if ($cols->isEmpty()) {
            throw ValidationException::withMessages(['grower' => 'Nothing collected from this grower is waiting to be settled.']);
        }
        $gross = round((float) $cols->sum('value'), 2);
        $owed = round((float) GrowerAdvance::query()->where('grower_id', $g->id)->get()->sum(fn ($a) => $a->value - $a->recovered), 2);
        $recover = min($gross, $owed);
        $n = GrowerSettlement::query()->where('company_id', $g->company_id)->count() + 1;

        return DB::transaction(function () use ($g, $cols, $gross, $recover, $n, $actor) {
            $s = GrowerSettlement::create(['company_id' => $g->company_id, 'grower_id' => $g->id, 'reference' => sprintf('GS-%s-%04d', now()->format('y'), $n), 'gross' => $gross,
                'advances_recovered' => $recover, 'net' => round($gross - $recover, 2), 'status' => 'draft', 'created_by' => $actor->id]);
            GrowerCollection::query()->whereIn('id', $cols->pluck('id'))->update(['settlement_id' => $s->id]);

            return $s;
        });
    }

    /**
     * Approved by someone else: the grower's bill for the gross, and the advances recovered against
     * it (Dr accounts payable / Cr grower advances), so what is left to pay is the net.
     */
    public function approveSettlement(GrowerSettlement $s, User $actor): GrowerSettlement
    {
        if ($s->status !== 'draft') {
            throw ValidationException::withMessages(['settlement' => 'That settlement is approved.']);
        }
        if ((int) $s->created_by === (int) $actor->id) {
            throw ValidationException::withMessages(['settlement' => 'Someone other than who drew up the settlement approves it.']);
        }
        $g = Grower::query()->findOrFail($s->grower_id);
        $farm = Farm::query()->findOrFail($g->farm_id);

        try {
            return DB::transaction(function () use ($s, $g, $farm, $actor) {
                $bill = app(ArApService::class)->createInvoice(['party_id' => $g->party_id, 'direction' => 'payable', 'invoice_date' => today()->toDateString(),
                    'party_ref' => $s->reference, 'description' => "Grower settlement {$s->reference}", 'relieve_stock' => false,
                    'lines' => [['account_id' => app(InventoryService::class)->accountIdFor(config('finance.grni_account', '2150')), 'description' => "Produce collected ({$s->reference})",
                        'quantity' => 1, 'unit_price' => $s->gross, 'tax_rate' => 0]]]);
                $journalId = null;
                if ($s->advances_recovered > 0) {
                    $j = $this->ledger->post(new FinancialEvent(sourceModule: 'agri', eventType: 'agri.grower_advance_recovered',
                        debits: [['account' => config('finance.ap_control'), 'amount' => $s->advances_recovered, 'narration' => "Advances recovered {$s->reference}"]],
                        credits: [['account' => config('agri.accounts.grower_advances'), 'amount' => $s->advances_recovered, 'narration' => "Advances recovered {$s->reference}",
                            'dimensions' => AgriLedger::dimensions($farm, null, null, 'grower_advance') + ['agri_grower' => (string) $g->id]]],
                        eventDate: today()->toDateString(), companyId: $g->company_id, currency: $farm->currency ?: 'ZAR', sourceRef: "grower_settlement:{$s->id}",
                        description: "Advances recovered from {$g->code}", idempotencyKey: "agri-grower-recover-{$s->uuid}"));
                    $journalId = $j->id;
                    // The recovery settles that much of the bill, as a payment would.
                    $bill->update(['amount_paid' => round((float) $bill->amount_paid + $s->advances_recovered, 2),
                        'status' => $s->advances_recovered >= (float) $bill->total - 0.005 ? 'paid' : 'part_paid']);
                    $left = $s->advances_recovered;
                    foreach (GrowerAdvance::query()->where('grower_id', $g->id)->orderBy('issued_on')->orderBy('id')->get() as $a) {
                        $take = min($left, round($a->value - $a->recovered, 2));
                        if ($take > 0) {
                            $a->update(['recovered' => round($a->recovered + $take, 2)]);
                            $left = round($left - $take, 2);
                        }
                    }
                }
                $s->update(['status' => 'approved', 'approved_by' => $actor->id, 'invoice_id' => $bill->id, 'recovery_journal_id' => $journalId]);

                return $s;
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['settlement' => $e->getMessage()]);
        }
    }

    public function statement(Grower $g): array
    {
        $adv = GrowerAdvance::query()->where('grower_id', $g->id)->get();
        $bills = FinInvoice::withoutGlobalScopes()->whereIn('id', GrowerSettlement::query()->where('grower_id', $g->id)->whereNotNull('invoice_id')->pluck('invoice_id'))->get();

        return ['advanced' => round((float) $adv->sum('value'), 2), 'recovered' => round((float) $adv->sum('recovered'), 2), 'advances_outstanding' => round((float) $adv->sum(fn ($a) => $a->value - $a->recovered), 2),
            'collected_value' => round((float) GrowerCollection::query()->where('grower_id', $g->id)->sum('value'), 2),
            'to_pay' => round((float) $bills->sum(fn ($b) => (float) $b->total - (float) $b->amount_paid), 2)];
    }
}
