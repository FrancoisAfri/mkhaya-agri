<?php

namespace App\Modules\Agri\Accounting;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Xero Accounting API 2.0. OAuth 2.0 (code flow, 30-minute access tokens, rotating refresh
 * tokens); the organisations a grant covers come from /connections and every call names one in
 * the xero-tenant-id header. Sales invoices are ACCREC invoices, bills ACCPAY, journals manual
 * journals (positive LineAmount debits, negative credits). Xero honours an Idempotency-Key header,
 * so a retried push is answered, not created twice, on its side as well as ours.
 */
class XeroConnector extends Connector
{
    public function provider(): string
    {
        return 'xero';
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->config('authorize_url').'?'.http_build_query(['response_type' => 'code', 'client_id' => $this->config('client_id'),
            'redirect_uri' => $redirectUri, 'scope' => $this->config('scopes'), 'state' => $state]);
    }

    public function tenants(array $tokens, array $query): array
    {
        $r = Http::withToken($tokens['access_token'])->acceptJson()->timeout(20)->get($this->config('connections_url'));

        return collect($this->ok($r, 'the list of organisations'))->where('tenantType', 'ORGANISATION')
            ->map(fn ($c) => ['id' => (string) $c['tenantId'], 'name' => (string) ($c['tenantName'] ?? $c['tenantId']), 'connection_id' => $c['id'] ?? null])->values()->all();
    }

    public function revoke(): void
    {
        $id = collect($this->connection->tenants ?? [])->firstWhere('id', $this->connection->tenant_id)['connection_id'] ?? null;
        try {
            if ($id) {
                Http::withToken($this->accessToken())->timeout(10)->delete(rtrim($this->config('connections_url'), '/').'/'.$id);
            }
        } catch (\Throwable) {
        }
        parent::revoke();
    }

    public function catalog(): array
    {
        $accounts = $this->ok($this->api()->get($this->url('Accounts')), 'the chart of accounts')['Accounts'] ?? [];
        $tax = $this->ok($this->api()->get($this->url('TaxRates')), 'the tax rates')['TaxRates'] ?? [];
        $tracking = $this->ok($this->api()->get($this->url('TrackingCategories')), 'the tracking categories')['TrackingCategories'] ?? [];

        return [
            'accounts' => collect($accounts)->where('Status', '!=', 'ARCHIVED')->map(fn ($a) => ['id' => $a['AccountID'], 'code' => $a['Code'] ?? null, 'name' => $a['Name'], 'type' => $a['Type'] ?? null])->values()->all(),
            'tax_rates' => collect($tax)->where('Status', '!=', 'DELETED')->map(fn ($t) => ['id' => $t['TaxType'], 'code' => $t['TaxType'], 'name' => $t['Name'], 'rate' => (float) ($t['EffectiveRate'] ?? 0)])->values()->all(),
            'tracking' => collect($tracking)->map(fn ($c) => ['id' => $c['TrackingCategoryID'], 'name' => $c['Name'],
                'options' => collect($c['Options'] ?? [])->map(fn ($o) => ['id' => $o['TrackingOptionID'], 'name' => $o['Name']])->values()->all()])->values()->all(),
            'items' => [],
        ];
    }

    public function contacts(): array
    {
        $out = [];
        for ($page = 1; $page <= 20; $page++) {
            $rows = $this->ok($this->api()->get($this->url('Contacts'), ['page' => $page]), 'the contacts')['Contacts'] ?? [];
            foreach ($rows as $c) {
                $out[] = ['id' => $c['ContactID'], 'name' => $c['Name'], 'tax_number' => $c['TaxNumber'] ?? null,
                    'kind' => ($c['IsSupplier'] ?? false) && ! ($c['IsCustomer'] ?? false) ? 'supplier' : 'customer'];
            }
            if (count($rows) < 100) {
                break;
            }
        }

        return $out;
    }

    public function findContact(string $name, ?string $taxNumber, string $kind): ?array
    {
        foreach (array_filter([$taxNumber ? 'TaxNumber=="'.addslashes($taxNumber).'"' : null, 'Name=="'.addslashes($name).'"']) as $where) {
            $c = ($this->ok($this->api()->get($this->url('Contacts'), ['where' => $where]), 'a contact search')['Contacts'] ?? [])[0] ?? null;
            if ($c) {
                return ['id' => $c['ContactID'], 'name' => $c['Name'], 'matched_by' => str_starts_with($where, 'TaxNumber') ? 'vat_number' : 'name'];
            }
        }

        return null;
    }

    public function createContact(array $party, string $kind): array
    {
        $body = ['Contacts' => [array_filter(['Name' => $party['name'], 'EmailAddress' => $party['email'] ?? null, 'TaxNumber' => $party['tax_number'] ?? null])]];
        $c = $this->ok($this->api()->post($this->url('Contacts'), $body), 'the new contact')['Contacts'][0] ?? null;

        return ['id' => (string) ($c['ContactID'] ?? throw new AccountingApiError('Xero created no contact.'))];
    }

    public function pushInvoice(array $doc, string $idempotencyKey): array
    {
        $invoice = array_filter([
            'Type' => $doc['type'] === 'bill' ? 'ACCPAY' : 'ACCREC',
            'Contact' => ['ContactID' => $doc['contact_id']],
            'Date' => $doc['date'], 'DueDate' => $doc['due_date'],
            // On a bill the number is the supplier's; ours goes in the reference so the two can be matched.
            'InvoiceNumber' => $doc['type'] === 'bill' ? ($doc['reference'] ?: $doc['number']) : $doc['number'],
            'Reference' => $doc['type'] === 'bill' ? $doc['number'] : $doc['reference'],
            'CurrencyCode' => $doc['currency'], 'Status' => 'AUTHORISED', 'LineAmountTypes' => 'Exclusive',
            'LineItems' => array_map(fn ($l) => array_filter(['Description' => $l['description'] ?: '-', 'Quantity' => $l['quantity'], 'UnitAmount' => $l['unit_price'],
                'AccountCode' => $l['account']['code'] ?? null, 'TaxType' => $l['tax']['code'] ?? null,
                'Tracking' => array_map(fn ($t) => ['Name' => $t['category'], 'Option' => $t['option']], self::tracking($l)) ?: null], fn ($v) => $v !== null), $doc['lines']),
        ], fn ($v) => $v !== null && $v !== '');
        $r = $this->ok($this->api()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post($this->url('Invoices'), ['Invoices' => [$invoice]]), 'the invoice');
        $x = $r['Invoices'][0] ?? [];

        return ['id' => (string) ($x['InvoiceID'] ?? throw new AccountingApiError('Xero returned no invoice id.')), 'number' => $x['InvoiceNumber'] ?? null];
    }

    public function pushJournal(array $doc, string $idempotencyKey): array
    {
        $j = ['Narration' => mb_strimwidth($doc['narration'] ?: $doc['number'], 0, 4000), 'Date' => $doc['date'], 'Status' => 'POSTED', 'LineAmountTypes' => 'NoTax',
            'JournalLines' => array_map(fn ($l) => array_filter(['LineAmount' => self::money($l['debit'] - $l['credit']), 'AccountCode' => $l['account']['code'] ?? null,
                'Description' => $l['description'] ?: null,
                'Tracking' => array_map(fn ($t) => ['Name' => $t['category'], 'Option' => $t['option']], self::tracking($l)) ?: null], fn ($v) => $v !== null), $doc['lines'])];
        $r = $this->ok($this->api()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post($this->url('ManualJournals'), ['ManualJournals' => [$j]]), 'the journal');
        $x = $r['ManualJournals'][0] ?? [];

        return ['id' => (string) ($x['ManualJournalID'] ?? throw new AccountingApiError('Xero returned no journal id.')), 'number' => null];
    }

    public function payments(?Carbon $since): array
    {
        $req = $this->api();
        if ($since) {
            $req = $req->withHeaders(['If-Modified-Since' => $since->copy()->utc()->format('Y-m-d\TH:i:s')]);
        }
        $rows = $this->ok($req->get($this->url('Payments'), ['where' => 'Status=="AUTHORISED"']), 'the payments')['Payments'] ?? [];

        return collect($rows)->filter(fn ($p) => ! empty($p['Invoice']['InvoiceID']))->map(fn ($p) => ['id' => (string) $p['PaymentID'], 'date' => self::date($p['Date'] ?? null),
            'kind' => str_starts_with((string) ($p['PaymentType'] ?? ''), 'ACCPAY') ? 'payment' : 'receipt', 'reference' => $p['Reference'] ?? null,
            'allocations' => [['document_id' => (string) $p['Invoice']['InvoiceID'], 'amount' => (float) $p['Amount']]]])->values()->all();
    }

    protected function errorMessage(Response $r): string
    {
        $j = $r->json() ?? [];
        $v = collect($j['Elements'] ?? [])->flatMap(fn ($e) => collect($e['ValidationErrors'] ?? [])->pluck('Message'))->filter()->implode(' ');

        return $v ?: ($j['Detail'] ?? $j['Message'] ?? $j['Title'] ?? mb_strimwidth($r->body(), 0, 300));
    }

    private function api(): PendingRequest
    {
        return $this->http()->withHeaders(['xero-tenant-id' => $this->requireTenant()]);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config('api_url'), '/').'/'.$path;
    }

    /** Xero's JSON dates: "/Date(1729468800000+0000)/" or ISO. */
    private static function date(?string $v): string
    {
        if ($v && preg_match('#/Date\((\d+)#', $v, $m)) {
            return Carbon::createFromTimestampMs((int) $m[1])->toDateString();
        }

        return $v ? Carbon::parse($v)->toDateString() : today()->toDateString();
    }
}
