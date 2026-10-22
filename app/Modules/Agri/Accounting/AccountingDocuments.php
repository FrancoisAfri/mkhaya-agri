<?php

namespace App\Modules\Agri\Accounting;

use App\Modules\Agri\Models\AccountingConnection;
use App\Modules\Agri\Models\AccountingMapping;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinJournal;
use Illuminate\Support\Facades\DB;

/**
 * Our documents in the shape every connector takes, with each of our codes already replaced by what
 * it is in the connected system: an account by its mapped account, a tax code by its mapped tax
 * rate, a farm / field / enterprise dimension by its mapped tracking option (Xero tracking
 * category, QuickBooks class or department, Sage analysis type). A missing account or tax mapping
 * stops the push with a message that says exactly what to map; tracking is optional.
 */
class AccountingDocuments
{
    /** @var array<string, array<string, AccountingMapping>> */
    private array $maps = [];

    public function invoice(AccountingConnection $c, FinInvoice $inv, string $contactId): array
    {
        $dims = $this->dimensionsOf($inv->dimensions, $inv->journal_id);
        $opts = $c->options();
        $lines = [];
        foreach ($inv->lines()->get() as $l) {
            $code = DB::table('fin_accounts')->where('id', $l->account_id)->value('code');
            $qty = (float) $l->quantity ?: 1.0;
            $net = round((float) $l->line_total, 2);
            $item = $this->map($c, 'item', (string) $code);
            $lines[] = [
                'description' => (string) ($l->description ?: $inv->description ?: $inv->number),
                'quantity' => round($qty, 4), 'unit_price' => round($net / $qty, 4), 'amount' => $net, 'tax_amount' => round((float) $l->tax_amount, 2),
                'account' => $this->account($c, (string) $code),
                'tax' => $l->tax_code ? $this->tax($c, (string) $l->tax_code) : $this->defaultTax($c),
                'item' => $item ? ['id' => $item->external_id] : ($opts['default_item'] ? ['id' => (string) $opts['default_item']] : null),
                'tracking' => $this->tracking($c, $dims),
            ];
        }

        return ['type' => $inv->direction === 'payable' ? 'bill' : 'invoice', 'number' => (string) $inv->number, 'reference' => $inv->party_ref ?: null,
            'date' => $inv->invoice_date?->toDateString(), 'due_date' => $inv->due_date?->toDateString(), 'currency' => (string) ($inv->currency ?: 'ZAR'),
            'contact_id' => $contactId, 'total' => (float) $inv->total, 'tax_total' => (float) $inv->tax_total, 'lines' => $lines];
    }

    public function journal(AccountingConnection $c, FinJournal $j): array
    {
        $rows = DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $j->id)->orderBy('l.id')
            ->get(['a.code', 'l.debit', 'l.credit', 'l.narration', 'l.dimensions']);
        $lines = $rows->map(fn ($l) => ['description' => (string) ($l->narration ?: $j->description ?: $j->journal_no), 'debit' => round((float) $l->debit, 2), 'credit' => round((float) $l->credit, 2),
            'account' => $this->account($c, (string) $l->code), 'tracking' => $this->tracking($c, json_decode((string) $l->dimensions, true) ?: [])])->values()->all();

        return ['number' => (string) $j->journal_no, 'date' => $j->journal_date?->toDateString() ?? (string) $j->journal_date, 'narration' => (string) ($j->description ?: $j->event_type),
            'currency' => (string) ($j->currency ?: 'ZAR'), 'lines' => $lines];
    }

    public function account(AccountingConnection $c, string $code): array
    {
        $m = $this->map($c, 'account', $code);
        if (! $m || (! $m->external_id && ! $m->external_code)) {
            throw new MappingMissing("Account {$code} is not mapped to a {$this->name($c)} account. Map it under Accounting connectors → Mapping, then retry.");
        }

        return ['id' => $m->external_id, 'code' => $m->external_code ?: $m->external_id, 'name' => $m->external_name, 'local' => $code];
    }

    private function tax(AccountingConnection $c, string $code): array
    {
        $m = $this->map($c, 'tax', $code);
        if (! $m || ! $m->external_id) {
            throw new MappingMissing("Tax code {$code} is not mapped to a {$this->name($c)} tax rate. Map it under Accounting connectors → Mapping, then retry.");
        }

        return ['id' => $m->external_id, 'code' => $m->external_code ?: $m->external_id, 'local' => $code];
    }

    /** A line with no tax code goes with the mapping for "no tax" if one is set, else with none (the provider's default for the account). */
    private function defaultTax(AccountingConnection $c): ?array
    {
        $m = $this->map($c, 'tax', '_none');

        return $m && $m->external_id ? ['id' => $m->external_id, 'code' => $m->external_code ?: $m->external_id, 'local' => null] : null;
    }

    /** @return list<array{dimension: string, category_id: ?string, category: ?string, option_id: ?string, option: ?string}> */
    public function tracking(AccountingConnection $c, array $dims): array
    {
        $keys = array_filter([
            'farm' => isset($dims['agri_farm']) ? 'farm:'.$dims['agri_farm'] : null,
            'field' => isset($dims['agri_field']) ? 'field:'.$dims['agri_field'] : null,
            'enterprise' => isset($dims['agri_enterprise']) ? 'enterprise:'.$dims['agri_enterprise'] : null,
        ]);
        $out = [];
        foreach ($keys as $dimension => $key) {
            $m = $this->map($c, 'tracking', $key);
            if ($m && $m->external_id) {
                $out[] = ['dimension' => $dimension, 'category_id' => $m->extra['category_id'] ?? null, 'category' => $m->extra['category'] ?? null,
                    'option_id' => $m->external_id, 'option' => $m->external_name];
            }
        }
        if ($c->provider === AccountingConnection::XERO) {
            // Xero takes at most two tracking categories on a line, one option each.
            $out = collect($out)->unique('category_id')->take((int) config('agri.accounting.providers.xero.tracking_categories', 2))->values()->all();
        }

        return $out;
    }

    /** An invoice's farm dimensions: its own, else those on its journal's lines. */
    public function dimensionsOf(?array $dims, ?int $journalId): array
    {
        if (! empty($dims['agri_farm'])) {
            return $dims;
        }
        $line = $journalId ? DB::table('fin_journal_lines')->where('journal_id', $journalId)->whereRaw("dimensions->>'agri_farm' IS NOT NULL")->value('dimensions') : null;

        return $line ? (json_decode((string) $line, true) ?: []) : ($dims ?? []);
    }

    public function forget(): void
    {
        $this->maps = [];
    }

    private function map(AccountingConnection $c, string $kind, string $key): ?AccountingMapping
    {
        $this->maps[$c->id.':'.$kind] ??= AccountingMapping::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', $kind)->get()->keyBy('local_key')->all();

        return $this->maps[$c->id.':'.$kind][$key] ?? null;
    }

    private function name(AccountingConnection $c): string
    {
        return (string) config("agri.accounting.providers.{$c->provider}.name", $c->provider);
    }
}
