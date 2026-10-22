<?php

namespace App\Modules\Agri\Accounting;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Sage Business Cloud Accounting API v3.1 (UK, Ireland, US, Canada, Spain, France, Germany).
 * OAuth 2.0 through Sage's central sign-in, where the person picks their country; access tokens
 * last minutes and refresh tokens rotate. The businesses a grant covers come from /businesses and
 * each call names one in the X-Business header. Ledger accounts, tax rates and analysis types
 * (Sage's tracking: up to three per business) are by id. Sage has no idempotency header: our link
 * table is what keeps a retry from creating a second document.
 *
 * South Africa's Sage Business Cloud Accounting is a different product with a different API (see
 * config agri.accounting.providers.sage.regions.za) and is refused before connecting.
 */
class SageConnector extends Connector
{
    public function provider(): string
    {
        return 'sage';
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        $base = (string) $this->config('authorize_url');

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query(['response_type' => 'code', 'client_id' => $this->config('client_id'),
            'redirect_uri' => $redirectUri, 'scope' => $this->config('scopes'), 'state' => $state]);
    }

    /** Sage takes the client credentials in the form body. */
    protected function tokenExtras(): array
    {
        return ['client_id' => $this->config('client_id'), 'client_secret' => $this->config('client_secret')];
    }

    public function tenants(array $tokens, array $query): array
    {
        $r = Http::withToken($tokens['access_token'])->acceptJson()->timeout(20)->get($this->url('businesses'));
        $rows = $this->ok($r, 'the list of businesses');

        return collect($rows['$items'] ?? $rows)->map(fn ($b) => ['id' => (string) $b['id'], 'name' => (string) ($b['name'] ?? $b['displayed_as'] ?? $b['id'])])->values()->all();
    }

    public function catalog(): array
    {
        return [
            'accounts' => collect($this->all('ledger_accounts', ['attributes' => 'nominal_code,ledger_account_type,visible_in_journals']))
                ->map(fn ($a) => ['id' => (string) $a['id'], 'code' => isset($a['nominal_code']) ? (string) $a['nominal_code'] : null, 'name' => $a['displayed_as'] ?? $a['name'] ?? '', 'type' => $a['ledger_account_type']['id'] ?? null])->values()->all(),
            'tax_rates' => collect($this->all('tax_rates', ['attributes' => 'percentage']))
                ->map(fn ($t) => ['id' => (string) $t['id'], 'code' => (string) $t['id'], 'name' => $t['displayed_as'] ?? $t['name'] ?? $t['id'], 'rate' => isset($t['percentage']) ? (float) $t['percentage'] : null])->values()->all(),
            'tracking' => collect($this->all('analysis_types', ['attributes' => 'analysis_type_categories']))
                ->map(fn ($c) => ['id' => (string) $c['id'], 'name' => $c['displayed_as'] ?? $c['name'] ?? $c['id'],
                    'options' => collect($c['analysis_type_categories'] ?? [])->map(fn ($o) => ['id' => (string) $o['id'], 'name' => $o['displayed_as'] ?? $o['name'] ?? $o['id']])->values()->all()])->values()->all(),
            'items' => [],
        ];
    }

    public function contacts(): array
    {
        return collect($this->all('contacts', ['attributes' => 'tax_number,contact_types']))->map(fn ($c) => ['id' => (string) $c['id'], 'name' => $c['displayed_as'] ?? $c['name'] ?? '',
            'tax_number' => $c['tax_number'] ?? null, 'kind' => collect($c['contact_types'] ?? [])->pluck('id')->contains('VENDOR') ? 'supplier' : 'customer'])->values()->all();
    }

    public function findContact(string $name, ?string $taxNumber, string $kind): ?array
    {
        $rows = $this->ok($this->api()->get($this->url('contacts'), ['search' => $taxNumber ?: $name, 'contact_type_id' => $kind === 'supplier' ? 'VENDOR' : 'CUSTOMER',
            'attributes' => 'tax_number']), 'a contact search')['$items'] ?? [];
        foreach ($rows as $c) {
            if ($taxNumber && ($c['tax_number'] ?? null) === $taxNumber) {
                return ['id' => (string) $c['id'], 'name' => $c['displayed_as'] ?? $name, 'matched_by' => 'vat_number'];
            }
            if (mb_strtolower(trim((string) ($c['displayed_as'] ?? $c['name'] ?? ''))) === mb_strtolower(trim($name))) {
                return ['id' => (string) $c['id'], 'name' => $c['displayed_as'] ?? $name, 'matched_by' => 'name'];
            }
        }

        return $taxNumber ? $this->findContact($name, null, $kind) : null;
    }

    public function createContact(array $party, string $kind): array
    {
        $r = $this->ok($this->api()->post($this->url('contacts'), ['contact' => array_filter(['name' => $party['name'], 'contact_type_ids' => [$kind === 'supplier' ? 'VENDOR' : 'CUSTOMER'],
            'tax_number' => $party['tax_number'] ?? null, 'email' => $party['email'] ?? null])]), 'the new contact');

        return ['id' => (string) ($r['id'] ?? throw new AccountingApiError('Sage created no contact.'))];
    }

    public function pushInvoice(array $doc, string $idempotencyKey): array
    {
        $bill = $doc['type'] === 'bill';
        $lines = array_map(fn ($l) => array_filter(['description' => $l['description'] ?: '-', 'ledger_account_id' => $l['account']['id'], 'quantity' => $l['quantity'],
            'unit_price' => $l['unit_price'], 'net_amount' => self::money($l['amount']), 'tax_rate_id' => $l['tax']['id'] ?? null, 'tax_amount' => isset($l['tax']) ? self::money($l['tax_amount']) : null,
            'analysis_type_categories' => array_map(fn ($t) => ['id' => $t['option_id']], self::tracking($l)) ?: null], fn ($v) => $v !== null), $doc['lines']);
        $body = array_filter(['contact_id' => $doc['contact_id'], 'date' => $doc['date'], 'due_date' => $doc['due_date'], 'currency_id' => $doc['currency'],
            ($bill ? 'vendor_reference' : 'reference') => $bill ? ($doc['reference'] ?: $doc['number']) : $doc['number'],
            'notes' => 'Mkhaya Agri '.$doc['number'], 'invoice_lines' => $lines]);
        $entity = $bill ? 'purchase_invoice' : 'sales_invoice';
        $r = $this->ok($this->api()->post($this->url($entity.'s'), [$entity => $body]), $bill ? 'the purchase invoice' : 'the sales invoice');

        return ['id' => (string) ($r['id'] ?? throw new AccountingApiError('Sage returned no invoice id.')), 'number' => $r['invoice_number'] ?? $r['displayed_as'] ?? null];
    }

    public function pushJournal(array $doc, string $idempotencyKey): array
    {
        $body = ['journal' => ['date' => $doc['date'], 'reference' => mb_strimwidth($doc['number'], 0, 25, ''), 'description' => mb_strimwidth((string) $doc['narration'], 0, 200, ''),
            'journal_lines' => array_map(fn ($l) => array_filter(['ledger_account_id' => $l['account']['id'], 'debit' => self::money($l['debit']), 'credit' => self::money($l['credit']),
                'details' => $l['description'] ?: null], fn ($v) => $v !== null), $doc['lines'])]];
        $r = $this->ok($this->api()->post($this->url('journals'), $body), 'the journal');

        return ['id' => (string) ($r['id'] ?? throw new AccountingApiError('Sage returned no journal id.')), 'number' => $r['reference'] ?? null];
    }

    public function payments(?Carbon $since): array
    {
        $params = ['attributes' => 'date,transaction_type,allocated_artefacts,reference,total_amount'] + ($since ? ['updated_or_created_since' => $since->copy()->utc()->toIso8601ZuluString()] : []);

        return collect($this->all('contact_payments', $params))->map(fn ($p) => ['id' => (string) $p['id'], 'date' => (string) ($p['date'] ?? today()->toDateString()),
            'kind' => ($p['transaction_type']['id'] ?? '') === 'VENDOR_PAYMENT' ? 'payment' : 'receipt', 'reference' => $p['reference'] ?? null,
            'allocations' => collect($p['allocated_artefacts'] ?? [])->filter(fn ($a) => ! empty($a['artefact']['id']))
                ->map(fn ($a) => ['document_id' => (string) $a['artefact']['id'], 'amount' => (float) ($a['amount'] ?? 0)])->values()->all()])
            ->filter(fn ($p) => $p['allocations'])->values()->all();
    }

    protected function errorMessage(Response $r): string
    {
        $j = $r->json();

        return collect(is_array($j) && array_is_list($j) ? $j : [$j])->map(fn ($e) => is_array($e) ? trim(($e['$message'] ?? $e['message'] ?? '').(isset($e['$source']) ? " ({$e['$source']})" : '')) : '')
            ->filter()->implode(' ') ?: mb_strimwidth($r->body(), 0, 300);
    }

    /** Every page of a list ($next links), up to a sane limit. */
    private function all(string $resource, array $params = []): array
    {
        $out = [];
        $url = $this->url($resource);
        $query = $params + ['items_per_page' => 200];
        for ($i = 0; $i < 25 && $url; $i++) {
            $r = $this->ok($this->api()->get($url, $query), "the {$resource}");
            $out = array_merge($out, $r['$items'] ?? []);
            $url = $r['$next'] ?? null;
            if ($url && ! str_starts_with($url, 'http')) {
                $url = rtrim((string) $this->config('api_url'), '/').'/'.ltrim($url, '/');
            }
            $query = [];
        }

        return $out;
    }

    private function api(): PendingRequest
    {
        return $this->http()->withHeaders(['X-Business' => $this->requireTenant()]);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config('api_url'), '/').'/'.$path;
    }
}
