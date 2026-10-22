<?php

namespace App\Modules\Agri\Accounting;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Intuit QuickBooks Online Accounting API v3. OAuth 2.0 (one-hour access tokens, refresh tokens
 * that rotate); the company (realmId) comes back on the callback and is part of every URL. Reads
 * are SQL-like queries. Sales invoice lines must name a product/service item (mapped per income
 * account, or the connection's default item); bills and journal entries post to accounts. Farm
 * dimensions go to classes and departments (locations). A `requestid` makes a retried create safe
 * on Intuit's side.
 */
class QuickBooksConnector extends Connector
{
    public function provider(): string
    {
        return 'quickbooks';
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->config('authorize_url').'?'.http_build_query(['client_id' => $this->config('client_id'), 'response_type' => 'code',
            'scope' => $this->config('scopes'), 'redirect_uri' => $redirectUri, 'state' => $state]);
    }

    public function tenants(array $tokens, array $query): array
    {
        $realm = (string) ($query['realmId'] ?? '');
        if ($realm === '') {
            throw new AccountingApiError('QuickBooks did not say which company was connected (no realmId).');
        }
        $name = $realm;
        try {
            $r = \Illuminate\Support\Facades\Http::withToken($tokens['access_token'])->acceptJson()->timeout(20)
                ->get(rtrim((string) $this->config('api_url'), '/')."/{$realm}/companyinfo/{$realm}", ['minorversion' => $this->config('minor_version')]);
            $name = $r->json('CompanyInfo.CompanyName') ?: $realm;
        } catch (\Throwable) {
        }

        return [['id' => $realm, 'name' => $name]];
    }

    public function catalog(): array
    {
        return [
            'accounts' => collect($this->query('Account', "Active = true"))->map(fn ($a) => ['id' => (string) $a['Id'], 'code' => $a['AcctNum'] ?? null, 'name' => $a['Name'], 'type' => $a['AccountType'] ?? null])->values()->all(),
            'tax_rates' => collect($this->query('TaxCode', "Active = true"))->map(fn ($t) => ['id' => (string) $t['Id'], 'code' => (string) $t['Id'], 'name' => $t['Name'], 'rate' => null])->values()->all(),
            'tracking' => [
                ['id' => 'class', 'name' => 'Class', 'options' => collect($this->query('Class'))->map(fn ($c) => ['id' => (string) $c['Id'], 'name' => $c['FullyQualifiedName'] ?? $c['Name']])->values()->all()],
                ['id' => 'department', 'name' => 'Department / location', 'options' => collect($this->query('Department'))->map(fn ($c) => ['id' => (string) $c['Id'], 'name' => $c['FullyQualifiedName'] ?? $c['Name']])->values()->all()],
            ],
            'items' => collect($this->query('Item', "Type IN ('Service', 'NonInventory')"))->map(fn ($i) => ['id' => (string) $i['Id'], 'name' => $i['Name'], 'income_account' => $i['IncomeAccountRef']['value'] ?? null])->values()->all(),
        ];
    }

    public function contacts(): array
    {
        $c = collect($this->query('Customer'))->map(fn ($x) => ['id' => (string) $x['Id'], 'name' => $x['DisplayName'], 'tax_number' => $x['PrimaryTaxIdentifier'] ?? null, 'kind' => 'customer']);
        $v = collect($this->query('Vendor'))->map(fn ($x) => ['id' => (string) $x['Id'], 'name' => $x['DisplayName'], 'tax_number' => $x['TaxIdentifier'] ?? null, 'kind' => 'supplier']);

        return $c->concat($v)->values()->all();
    }

    public function findContact(string $name, ?string $taxNumber, string $kind): ?array
    {
        $entity = $kind === 'supplier' ? 'Vendor' : 'Customer';
        $row = $this->query($entity, "DisplayName = '".str_replace("'", "\\'", $name)."'")[0] ?? null;

        return $row ? ['id' => (string) $row['Id'], 'name' => $row['DisplayName'], 'matched_by' => 'name'] : null;
    }

    public function createContact(array $party, string $kind): array
    {
        $entity = $kind === 'supplier' ? 'vendor' : 'customer';
        $body = array_filter(['DisplayName' => mb_strimwidth($party['name'], 0, 100, ''), 'CompanyName' => mb_strimwidth($party['name'], 0, 100, ''),
            'PrimaryEmailAddr' => ! empty($party['email']) ? ['Address' => $party['email']] : null]);
        $r = $this->ok($this->api()->post($this->url($entity), $body), 'the new contact');

        return ['id' => (string) ($r[ucfirst($entity)]['Id'] ?? throw new AccountingApiError('QuickBooks created no contact.'))];
    }

    public function pushInvoice(array $doc, string $idempotencyKey): array
    {
        $bill = $doc['type'] === 'bill';
        $lines = array_map(function ($l) use ($bill) {
            $class = collect(self::tracking($l))->firstWhere('target', 'class') ?? collect(self::tracking($l))->first(fn ($t) => ($t['category_id'] ?? null) === 'class');
            $common = array_filter(['TaxCodeRef' => ! empty($l['tax']['id']) ? ['value' => $l['tax']['id']] : null, 'ClassRef' => $class ? ['value' => $class['option_id']] : null]);
            if ($bill) {
                return ['DetailType' => 'AccountBasedExpenseLineDetail', 'Amount' => self::money($l['amount']), 'Description' => $l['description'],
                    'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $l['account']['id']]] + $common];
            }
            $item = $l['item']['id'] ?? throw new MappingMissing('QuickBooks needs a product/service item for each invoice line: map one to account '.($l['account']['local'] ?? '').' or set a default item.');

            return ['DetailType' => 'SalesItemLineDetail', 'Amount' => self::money($l['amount']), 'Description' => $l['description'],
                'SalesItemLineDetail' => ['ItemRef' => ['value' => $item], 'Qty' => $l['quantity'], 'UnitPrice' => $l['unit_price']] + $common];
        }, $doc['lines']);
        $department = collect($doc['lines'])->flatMap(fn ($l) => self::tracking($l))->first(fn ($t) => ($t['target'] ?? $t['category_id'] ?? null) === 'department');
        $body = array_filter([
            $bill ? 'VendorRef' : 'CustomerRef' => ['value' => $doc['contact_id']],
            'DocNumber' => mb_strimwidth($bill ? ($doc['reference'] ?: $doc['number']) : $doc['number'], 0, 21, ''),
            'PrivateNote' => $bill ? 'Mkhaya Agri '.$doc['number'] : null,
            'TxnDate' => $doc['date'], 'DueDate' => $doc['due_date'], 'CurrencyRef' => ['value' => $doc['currency']],
            'DepartmentRef' => $department ? ['value' => $department['option_id']] : null, 'Line' => $lines,
        ]);
        $entity = $bill ? 'bill' : 'invoice';
        $r = $this->ok($this->api()->post($this->url($entity).'&requestid='.urlencode(mb_strimwidth($idempotencyKey, 0, 50, '')), $body), "the {$entity}");
        $x = $r[ucfirst($entity)] ?? [];

        return ['id' => (string) ($x['Id'] ?? throw new AccountingApiError("QuickBooks returned no {$entity} id.")), 'number' => $x['DocNumber'] ?? null];
    }

    public function pushJournal(array $doc, string $idempotencyKey): array
    {
        $lines = [];
        foreach ($doc['lines'] as $l) {
            $amount = self::money(abs($l['debit'] - $l['credit']));
            if ($amount == 0.0) {
                continue;
            }
            $t = collect(self::tracking($l));
            $class = $t->first(fn ($x) => ($x['target'] ?? $x['category_id'] ?? null) === 'class');
            $dept = $t->first(fn ($x) => ($x['target'] ?? $x['category_id'] ?? null) === 'department');
            $lines[] = ['DetailType' => 'JournalEntryLineDetail', 'Amount' => $amount, 'Description' => $l['description'],
                'JournalEntryLineDetail' => array_filter(['PostingType' => $l['debit'] >= $l['credit'] ? 'Debit' : 'Credit', 'AccountRef' => ['value' => $l['account']['id']],
                    'ClassRef' => $class ? ['value' => $class['option_id']] : null, 'DepartmentRef' => $dept ? ['value' => $dept['option_id']] : null])];
        }
        $body = ['TxnDate' => $doc['date'], 'DocNumber' => mb_strimwidth($doc['number'], 0, 21, ''), 'PrivateNote' => mb_strimwidth((string) $doc['narration'], 0, 4000), 'Line' => $lines];
        $r = $this->ok($this->api()->post($this->url('journalentry').'&requestid='.urlencode(mb_strimwidth($idempotencyKey, 0, 50, '')), $body), 'the journal entry');

        return ['id' => (string) ($r['JournalEntry']['Id'] ?? throw new AccountingApiError('QuickBooks returned no journal entry id.')), 'number' => $r['JournalEntry']['DocNumber'] ?? null];
    }

    public function payments(?Carbon $since): array
    {
        $where = $since ? "MetaData.LastUpdatedTime > '".$since->copy()->utc()->format('Y-m-d\TH:i:s\Z')."'" : null;
        $out = [];
        foreach (['Payment' => ['receipt', 'Invoice', 'TotalAmt'], 'BillPayment' => ['payment', 'Bill', 'TotalAmt']] as $entity => [$kind, $txnType]) {
            foreach ($this->query($entity, $where) as $p) {
                $alloc = [];
                foreach ($p['Line'] ?? [] as $line) {
                    foreach ($line['LinkedTxn'] ?? [] as $lt) {
                        if (($lt['TxnType'] ?? null) === $txnType) {
                            $alloc[] = ['document_id' => (string) $lt['TxnId'], 'amount' => (float) ($line['Amount'] ?? 0)];
                        }
                    }
                }
                if ($alloc) {
                    $out[] = ['id' => (string) $p['Id'], 'date' => (string) ($p['TxnDate'] ?? today()->toDateString()), 'kind' => $kind, 'reference' => $p['PaymentRefNum'] ?? $p['DocNumber'] ?? null, 'allocations' => $alloc];
                }
            }
        }

        return $out;
    }

    protected function errorMessage(Response $r): string
    {
        $e = $r->json('Fault.Error') ?? $r->json('fault.error') ?? [];

        return collect($e)->map(fn ($x) => trim(($x['Message'] ?? $x['message'] ?? '').': '.($x['Detail'] ?? $x['detail'] ?? ''), ': '))->filter()->implode(' ')
            ?: mb_strimwidth($r->body(), 0, 300);
    }

    /** @return list<array> */
    private function query(string $entity, ?string $where = null): array
    {
        $q = "select * from {$entity}".($where ? " where {$where}" : '').' maxresults 1000';
        $r = $this->ok($this->api()->get(rtrim((string) $this->config('api_url'), '/').'/'.$this->requireTenant().'/query', ['query' => $q, 'minorversion' => $this->config('minor_version')]), "the {$entity} list");

        return $r['QueryResponse'][$entity] ?? [];
    }

    private function api(): PendingRequest
    {
        return $this->http();
    }

    private function url(string $entity): string
    {
        return rtrim((string) $this->config('api_url'), '/').'/'.$this->requireTenant().'/'.$entity.'?minorversion='.$this->config('minor_version');
    }
}
