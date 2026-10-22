<?php

namespace App\Modules\Agri\Accounting;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Sage Business Cloud Accounting South Africa (the former Sage One SA, of the Pastel family): a
 * different product from the v3.1 API in SageConnector, with its own "Accounting API" v2.0.0.
 *
 * No OAuth. Every call carries the API key Sage issued to the integrator (the apikey query
 * parameter; the platform's from config, or the organisation's own), the person's Sage username and
 * password as HTTP Basic, and, past Company/Get, the company id (companyid). The connection keeps
 * {username, password, api_key?} encrypted where the OAuth providers keep their tokens; the company
 * chosen from Company/Get is the connection's tenant.
 *
 * What it speaks: Account/Get, TaxType/Get, Customer/Get|Save, Supplier/Get|Save, TaxInvoice/Save,
 * SupplierInvoice/Save, JournalEntry/Save, CustomerReceipt/Get, SupplierPayment/Get, with OData
 * paging ($top at most 100, $skip) and $filter. Lists come back as {TotalResults, ReturnedResults,
 * Results}.
 *
 *  - Documents go as account lines (LineType 1, SelectionId = the account) with the mapped tax type.
 *  - Tracking: Sage SA has up to three analysis categories per company, each with its codes; a line
 *    takes AnalysisCategoryId1..3 (the code for category 1, 2, 3). Farm / field / enterprise are also
 *    written into the line description so they are visible in Sage even where nothing is mapped.
 *  - Journals: Sage SA's JournalEntry is one amount from an account to a contra account, not a
 *    multi-line journal. Our balanced journal is split into the fewest such pairs (each debit line
 *    matched against credit lines), each with the journal's number as its reference (".../2" on).
 *  - Idempotency: Sage SA has no idempotency header. Before anything is created we look it up by our
 *    reference, so a push whose answer was lost does not create a second copy on the retry.
 *  - External ids are kept as "TaxInvoice:123", "SupplierInvoice:45", "JournalEntry:7,8" and
 *    contacts as "customer:12" / "supplier:9": Sage SA numbers each entity separately.
 *  - Rate limits: calls are throttled per company (config per_minute) and a 429 / 503 is retried
 *    after Retry-After, a few times.
 */
class SageSouthAfricaConnector extends Connector
{
    public const LINE_ACCOUNT = 1;      // CommercialDocumentLine.LineType: 0 item, 1 account

    public const EFFECT_DEBIT = 1;      // JournalEntry.Effect: 1 debit, 2 credit

    public function provider(): string
    {
        return 'sage';
    }

    /** Its own settings (config agri.accounting.providers.sage.regions.za), named as the product. */
    public function config(?string $key = null): mixed
    {
        $c = config('agri.accounting.providers.sage.regions.za', []);
        $c['name'] = $c['product'] ?? 'Sage Business Cloud Accounting (South Africa)';

        return $key ? ($c[$key] ?? null) : $c;
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        throw new AccountingApiError($this->config('name').' signs in with the Sage username and password, not a redirect.');
    }

    public function exchange(string $code, string $redirectUri): array
    {
        throw new AccountingApiError($this->config('name').' has no authorisation code to exchange.');
    }

    public function refreshTokens(string $refreshToken): array
    {
        throw new AccountingApiError($this->config('name').' has no tokens to refresh.');
    }

    /** Nothing to revoke: forgetting the password (disconnect) is the whole of it. */
    public function revoke(): void {}

    /** The companies this sign-in may use. $tokens: the credentials being checked {username, password, api_key?}. */
    public function tenants(array $tokens, array $query): array
    {
        $rows = $this->all('Company/Get', [], 'the list of companies', $tokens, false);

        return collect($rows)->filter(fn ($c) => ($c['Active'] ?? true) !== false)
            ->map(fn ($c) => ['id' => (string) $c['ID'], 'name' => (string) ($c['Name'] ?? $c['ID'])])->values()->all();
    }

    public function catalog(): array
    {
        return [
            'accounts' => collect($this->all('Account/Get', [], 'the chart of accounts'))->filter(fn ($a) => ($a['Active'] ?? true) !== false)
                ->map(fn ($a) => ['id' => (string) $a['ID'], 'code' => self::accountCode($a), 'name' => (string) ($a['Name'] ?? $a['ID']),
                    'type' => $a['Category']['Description'] ?? null])->values()->all(),
            'tax_rates' => collect($this->all('TaxType/Get', [], 'the tax types'))->filter(fn ($t) => ($t['Active'] ?? true) !== false)
                ->map(fn ($t) => ['id' => (string) $t['ID'], 'code' => (string) $t['ID'], 'name' => (string) ($t['Name'] ?? $t['ID']),
                    'rate' => isset($t['Percentage']) ? (float) $t['Percentage'] : null])->values()->all(),
            'tracking' => $this->analysis(),
            'items' => [],
        ];
    }

    public function contacts(): array
    {
        $map = fn (string $kind) => fn ($c) => ['id' => $kind.':'.$c['ID'], 'name' => (string) ($c['Name'] ?? ''), 'tax_number' => $c['TaxReference'] ?? null, 'kind' => $kind];

        return [...array_map($map('customer'), $this->all('Customer/Get', [], 'the customers')), ...array_map($map('supplier'), $this->all('Supplier/Get', [], 'the suppliers'))];
    }

    public function findContact(string $name, ?string $taxNumber, string $kind): ?array
    {
        $entity = $kind === 'supplier' ? 'Supplier' : 'Customer';
        foreach (array_filter(['vat_number' => $taxNumber ? 'TaxReference eq '.self::str($taxNumber) : null, 'name' => 'Name eq '.self::str($name)]) as $by => $filter) {
            $rows = $this->call('GET', "{$entity}/Get", ['$filter' => $filter, '$top' => 5], what: 'a '.strtolower($entity).' search')['Results'] ?? [];
            if ($hit = $rows[0] ?? null) {
                return ['id' => $kind.':'.$hit['ID'], 'name' => (string) ($hit['Name'] ?? $name), 'matched_by' => $by];
            }
        }

        return null;
    }

    public function createContact(array $party, string $kind): array
    {
        $entity = $kind === 'supplier' ? 'Supplier' : 'Customer';
        $r = $this->call('POST', "{$entity}/Save", [], array_filter(['Name' => mb_strimwidth($party['name'], 0, 100, ''), 'TaxReference' => $party['tax_number'] ?? null,
            'Email' => $party['email'] ?? null, 'Active' => true], fn ($v) => $v !== null), 'the new '.strtolower($entity));

        return ['id' => $kind.':'.($r['ID'] ?? throw new AccountingApiError("{$this->config('name')} created no {$entity}."))];
    }

    /** Sage SA keeps customers and suppliers apart: a customer's id is no use on a supplier invoice. */
    public function contactFits(string $externalId, string $kind): bool
    {
        return str_starts_with($externalId, $kind.':');
    }

    public function pushInvoice(array $doc, string $idempotencyKey): array
    {
        $bill = $doc['type'] === 'bill';
        $entity = $bill ? 'SupplierInvoice' : 'TaxInvoice';
        $party = $bill ? 'SupplierId' : 'CustomerId';
        $contact = self::id($doc['contact_id'], $bill ? 'supplier' : 'customer');
        $ref = mb_strimwidth($doc['number'], 0, 100, '');

        if ($found = $this->existing($entity, $ref, [$party => $contact])) {
            return ['id' => "{$entity}:{$found['ID']}", 'number' => $found['DocumentNumber'] ?? null];
        }
        $lines = array_map(fn ($l) => array_filter([
            'LineType' => self::LINE_ACCOUNT,
            'SelectionId' => self::num($l['account']['id']),
            'TaxTypeId' => isset($l['tax']['id']) ? self::num($l['tax']['id']) : null,
            'Description' => $this->describe($l),
            'Quantity' => $l['quantity'],
            'UnitPriceExclusive' => $l['unit_price'],
            'Exclusive' => self::money($l['amount']),
            'Tax' => isset($l['tax']) ? self::money($l['tax_amount']) : 0.0,
            'Total' => self::money($l['amount'] + (isset($l['tax']) ? $l['tax_amount'] : 0)),
        ] + $this->analysisFields($l), fn ($v) => $v !== null), $doc['lines']);
        $body = array_filter([$party => $contact, 'Date' => $doc['date'], 'DueDate' => $doc['due_date'], 'Reference' => $ref, 'Inclusive' => false,
            'Message' => mb_strimwidth('Mkhaya Agri '.$doc['number'].($bill && $doc['reference'] ? ' · supplier ref '.$doc['reference'] : ''), 0, 250, ''),
            'Lines' => $lines], fn ($v) => $v !== null);
        $r = $this->call('POST', "{$entity}/Save", [], $body, $bill ? 'the supplier invoice' : 'the tax invoice');

        return ['id' => "{$entity}:".($r['ID'] ?? throw new AccountingApiError("{$this->config('name')} returned no {$entity} id.")), 'number' => $r['DocumentNumber'] ?? null];
    }

    /**
     * Our balanced journal as Sage SA journal entries: each one amount debited to an account and
     * credited to a contra account. Each entry is looked up by its reference before it is made, so a
     * retry after a part-way failure only makes the ones still missing.
     */
    public function pushJournal(array $doc, string $idempotencyKey): array
    {
        $pairs = self::pairs($doc['lines']);
        if (! $pairs) {
            throw new AccountingApiError("Journal {$doc['number']} has no amounts to send.");
        }
        $ids = [];
        foreach ($pairs as $i => [$dr, $cr, $amount]) {
            $ref = mb_strimwidth($doc['number'], 0, 90, '').(count($pairs) > 1 ? '/'.($i + 1) : '');
            if ($found = $this->existing('JournalEntry', $ref)) {
                $ids[] = (string) $found['ID'];

                continue;
            }
            $tagged = self::tracking($dr) ? $dr : $cr;
            $r = $this->call('POST', 'JournalEntry/Save', [], array_filter([
                'Date' => $doc['date'], 'Effect' => self::EFFECT_DEBIT, 'AccountId' => self::num($dr['account']['id']), 'ContraAccountId' => self::num($cr['account']['id']),
                'Amount' => self::money($amount), 'Reference' => $ref,
                'Description' => mb_strimwidth($this->describe($tagged, $doc['narration'] ?: null), 0, 250, ''),
            ] + $this->analysisFields($tagged), fn ($v) => $v !== null), 'the journal entry');
            $ids[] = (string) ($r['ID'] ?? throw new AccountingApiError("{$this->config('name')} returned no journal entry id."));
        }
        $id = 'JournalEntry:'.implode(',', $ids);

        return ['id' => strlen($id) <= 120 ? $id : 'JournalEntry:'.$ids[0].'..'.end($ids), 'number' => $doc['number']];
    }

    public function payments(?Carbon $since): array
    {
        $filter = $since ? ['$filter' => "Modified ge datetime'".$since->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:s')."'"] : [];
        $out = [];
        foreach (['receipt' => ['CustomerReceipt', 'TaxInvoice'], 'payment' => ['SupplierPayment', 'SupplierInvoice']] as $kind => [$entity, $docEntity]) {
            foreach ($this->all("{$entity}/Get", $filter, $kind === 'receipt' ? 'the customer receipts' : 'the supplier payments') as $p) {
                $allocs = ! empty($p['Allocations']) && is_array($p['Allocations']) ? $p['Allocations'] : $this->allocations($p['ID']);
                $allocations = collect($allocs)->map(fn ($a) => ['document_id' => $docEntity.':'.($a['AllocatedToDocumentId'] ?? $a['DocumentId'] ?? $a['InvoiceId'] ?? ''),
                    'amount' => (float) ($a['Total'] ?? $a['Amount'] ?? 0)])->filter(fn ($a) => ! str_ends_with($a['document_id'], ':'))->values()->all();
                if ($allocations) {
                    $out[] = ['id' => "{$kind}:{$p['ID']}", 'date' => substr((string) ($p['Date'] ?? today()->toDateString()), 0, 10), 'kind' => $kind,
                        'reference' => $p['Reference'] ?? null, 'allocations' => $allocations];
                }
            }
        }

        return $out;
    }

    protected function errorMessage(Response $r): string
    {
        $j = $r->json();
        $msg = match (true) {
            is_string($j) => $j,
            is_array($j) => trim(implode(' ', array_filter([$j['Message'] ?? $j['message'] ?? $j['error'] ?? null,
                isset($j['ModelState']) && is_array($j['ModelState']) ? collect($j['ModelState'])->flatten()->implode(' ') : null,
                $j['ExceptionMessage'] ?? null]))),
            default => '',
        };
        $msg = $msg !== '' ? $msg : mb_strimwidth(trim(strip_tags($r->body())), 0, 300);

        return $r->status() === 401 ? 'Sage did not accept the username, password or API key. '.$msg : $msg;
    }

    // ── the wire ─────────────────────────────────────────────────────────────────────────────

    /**
     * One call: API key and company id on the query, the Sage sign-in as HTTP Basic. Throttled per
     * company; a 429 or 503 waits (Retry-After, else a growing pause) and tries again a few times.
     */
    private function call(string $method, string $endpoint, array $query = [], ?array $body = null, string $what = 'the request', ?array $credentials = null, bool $company = true): array
    {
        $cred = $credentials ?? $this->connection->tokens();
        if (blank($cred['username'] ?? null) || blank($cred['password'] ?? null)) {
            throw new AccountingApiError('Not signed in: enter the Sage username and password for '.$this->config('name').' again.');
        }
        $q = ['apikey' => $this->apiKey($cred)] + ($company ? ['companyid' => $this->requireTenant()] : []) + $query;
        $url = rtrim((string) $this->config('api_url'), '/').'/'.$endpoint.'?'.http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        $retries = (int) ($this->config('retries') ?? 3);
        for ($attempt = 0; ; $attempt++) {
            $this->throttle();
            $req = Http::withBasicAuth((string) $cred['username'], (string) $cred['password'])->acceptJson()->timeout((int) config('agri.accounting.timeout', 30));
            $r = $method === 'GET' ? $req->get($url) : $req->asJson()->post($url, $body ?? []);
            if (in_array($r->status(), [429, 503], true) && $attempt < $retries) {
                $wait = (int) $r->header('Retry-After') ?: 2 ** $attempt;
                Sleep::for(max(1, min($wait, (int) ($this->config('max_wait') ?? 60))))->seconds();

                continue;
            }
            if (! $r->successful()) {
                throw new AccountingApiError("{$this->config('name')} refused {$what} ({$r->status()}): {$this->errorMessage($r)}", $r->status());
            }
            $j = $r->json();

            return is_array($j) ? $j : ['value' => $j];
        }
    }

    /** Every page of a list, $top / $skip, up to config max_pages. */
    private function all(string $endpoint, array $query, string $what, ?array $credentials = null, bool $company = true): array
    {
        $top = max(1, min(100, (int) ($this->config('page_size') ?? 100)));
        $out = [];
        $skip = 0;
        for ($page = 0; $page < (int) ($this->config('max_pages') ?? 50); $page++) {
            $r = $this->call('GET', $endpoint, $query + ['$top' => $top, '$skip' => $skip], null, $what, $credentials, $company);
            $rows = $r['Results'] ?? (array_is_list($r) ? $r : []);
            $out = array_merge($out, $rows);
            $skip += count($rows);
            $total = isset($r['TotalResults']) ? (int) $r['TotalResults'] : null;
            if (count($rows) < $top || ($total !== null && $skip >= $total)) {
                break;
            }
        }

        return $out;
    }

    private function throttle(): void
    {
        $key = 'agri-sage-za:'.$this->connection->company_id;
        $limit = max(1, (int) ($this->config('per_minute') ?? 100));
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            Sleep::for(max(1, min(RateLimiter::availableIn($key), (int) ($this->config('max_wait') ?? 60))))->seconds();
            RateLimiter::clear($key);
        }
        RateLimiter::hit($key, 60);
    }

    private function apiKey(array $cred): string
    {
        $key = ($cred['api_key'] ?? null) ?: $this->config('api_key');

        return filled($key) ? (string) $key
            : throw new AccountingApiError('No Sage South Africa API key: the platform\'s (SAGE_ZA_API_KEY) is not set and this organisation has not entered its own.');
    }

    /** A document already there under our reference (and for this customer or supplier), or null. */
    private function existing(string $entity, string $ref, array $and = []): ?array
    {
        $filter = implode(' and ', ['Reference eq '.self::str($ref), ...array_map(fn ($k, $v) => "{$k} eq {$v}", array_keys($and), $and)]);
        $rows = $this->call('GET', "{$entity}/Get", ['$filter' => $filter, '$top' => 5], what: "a look-up of {$ref}")['Results'] ?? [];

        return collect($rows)->first(fn ($x) => (string) ($x['Reference'] ?? '') === $ref);
    }

    /** The allocations of a receipt or payment that did not carry them itself. */
    private function allocations(int|string $sourceId): array
    {
        try {
            return $this->call('GET', (string) $this->config('allocations'), ['$filter' => 'SourceDocumentId eq '.self::num((string) $sourceId)], what: 'the allocations')['Results'] ?? [];
        } catch (AccountingApiError $e) {
            if (in_array($e->status, [400, 404], true)) {
                return [];
            }
            throw $e;
        }
    }

    /** Analysis categories (at most three) with their codes; none where the company or API has none. */
    private function analysis(): array
    {
        try {
            $cats = $this->all((string) $this->config('analysis_categories'), [], 'the analysis categories');
            $codes = $cats ? $this->all((string) $this->config('analysis_codes'), [], 'the analysis codes') : [];
        } catch (AccountingApiError $e) {
            if (in_array($e->status, [400, 404], true)) {
                return [];
            }
            throw $e;
        }

        return collect($cats)->sortBy(fn ($c) => (int) $c['ID'])->values()->take(3)->map(fn ($c, $i) => [
            'id' => (string) $c['ID'], 'name' => (string) ($c['Description'] ?? $c['Name'] ?? $c['ID']), 'slot' => $i + 1,
            'options' => collect($codes)->filter(fn ($o) => (string) ($o['AnalysisCategoryId'] ?? '') === (string) $c['ID'])
                ->map(fn ($o) => ['id' => (string) $o['ID'], 'name' => (string) ($o['Description'] ?? $o['Name'] ?? $o['Code'] ?? $o['ID'])])->values()->all(),
        ])->all();
    }

    /** AnalysisCategoryId1..3 for a line's mapped tracking, by the slot of each category. */
    private function analysisFields(array $line): array
    {
        $slots = collect($this->connection->catalog['tracking'] ?? [])->mapWithKeys(fn ($c, $i) => [(string) $c['id'] => (int) ($c['slot'] ?? $i + 1)]);
        $out = [];
        foreach (self::tracking($line) as $t) {
            $slot = $slots[(string) ($t['category_id'] ?? '')] ?? null;
            if ($slot && $slot <= 3 && ! isset($out["AnalysisCategoryId{$slot}"]) && ! empty($t['option_id'])) {
                $out["AnalysisCategoryId{$slot}"] = self::num($t['option_id']);
            }
        }

        return $out;
    }

    /** The line's description with its farm / field / enterprise after it: "White maize [Farm NORTH · Field N-03]". */
    private function describe(array $line, ?string $fallback = null): string
    {
        $d = trim((string) ($line['description'] ?? '')) ?: ($fallback ?? '-');
        $labels = $this->config('tracking_in_description') ? array_values($line['labels'] ?? []) : [];

        return mb_strimwidth($labels ? $d.' ['.implode(' · ', $labels).']' : $d, 0, 250, '');
    }

    /**
     * A balanced journal's lines as (debit line, credit line, amount) pairs: each debit is met from
     * the credit lines in order, so N lines become at most N-1 entries and every entry balances.
     *
     * @return list<array{0: array, 1: array, 2: float}>
     */
    private static function pairs(array $lines): array
    {
        $dr = array_values(array_filter($lines, fn ($l) => round((float) $l['debit'], 2) > 0));
        $cr = array_values(array_filter($lines, fn ($l) => round((float) $l['credit'], 2) > 0));
        $left = array_map(fn ($l) => round((float) $l['credit'], 2), $cr);
        $out = [];
        $j = 0;
        foreach ($dr as $d) {
            $need = round((float) $d['debit'], 2);
            while ($need > 0.004 && $j < count($cr)) {
                $take = round(min($need, $left[$j]), 2);
                if ($take > 0) {
                    $out[] = [$d, $cr[$j], $take];
                }
                $need = round($need - $take, 2);
                $left[$j] = round($left[$j] - $take, 2);
                if ($left[$j] <= 0.004) {
                    $j++;
                }
            }
            if ($need > 0.004) {
                throw new AccountingApiError('The journal does not balance; it cannot be sent as Sage journal entries.');
            }
        }

        return $out;
    }

    /** "customer:12" → 12, refusing an id of the wrong kind. */
    private static function id(string $external, string $kind): int|string
    {
        if (! str_contains($external, ':')) {
            return self::num($external);
        }
        [$k, $id] = explode(':', $external, 2);
        if ($k !== $kind) {
            throw new AccountingApiError("The contact is a Sage {$k}; this document needs a {$kind}.");
        }

        return self::num($id);
    }

    private static function num(string $v): int|string
    {
        return ctype_digit($v) ? (int) $v : $v;
    }

    /** An OData string literal. */
    private static function str(string $v): string
    {
        return "'".str_replace("'", "''", $v)."'";
    }

    private static function accountCode(array $a): ?string
    {
        foreach (['AccountNumber', 'Code', 'Number'] as $k) {
            if (isset($a[$k]) && $a[$k] !== '') {
                return (string) $a[$k];
            }
        }

        return null;
    }
}
