<?php

namespace App\Modules\Agri\Accounting;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\AccountingConnection;
use App\Modules\Agri\Models\AccountingLink;
use App\Modules\Agri\Models\AccountingMapping;
use App\Modules\Agri\Models\AgriOrgSetting;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Services\Integrations;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ArApService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The farm's books in Xero, QuickBooks Online or Sage.
 *
 * An organisation has ONE book of record. With our own ledger (the default) nothing here runs and
 * every posting is the books, as before. With a connected external system, our ledger keeps
 * recording everything (the farm reports, profitability and reconciliation read it), but it becomes
 * the sub-ledger: the farm's sales invoices and settlements, purchase bills and journals (cost
 * allocations, stock issues, harvest valuations) are queued for export to the external system, and
 * payments recorded there come back and mark our invoices and bills paid.
 *
 * Every record goes out as one integration message keyed on provider, type and record, and keeps the
 * id it got there (agri_accounting_links), so a retry — ours or the scheduler's — never creates a
 * second copy. A journal that is an invoice's or a payment's own posting is not sent again as a
 * journal: the invoice carries it.
 */
class AccountingSync
{
    public function __construct(private readonly AccountingConnections $connections, private readonly AccountingDocuments $documents) {}

    public const BOOKS = ['internal', AccountingConnection::XERO, AccountingConnection::QUICKBOOKS, AccountingConnection::SAGE];

    public function bookOfRecord(int $companyId): string
    {
        return (string) (AgriOrgSetting::for($companyId)->book_of_record ?: 'internal');
    }

    public function setBookOfRecord(int $companyId, string $book, User $actor): AgriOrgSetting
    {
        if (! in_array($book, self::BOOKS, true)) {
            throw ValidationException::withMessages(['book_of_record' => 'Choose our own ledger, Xero, QuickBooks or Sage.']);
        }
        if ($book !== 'internal' && ! AccountingConnection::for($companyId, $book)->isConnected()) {
            throw ValidationException::withMessages(['book_of_record' => 'Connect '.config("agri.accounting.providers.{$book}.name").' and choose its organisation first.']);
        }
        $s = AgriOrgSetting::for($companyId);
        if ($s->book_of_record !== $book) {
            $s->update(['book_of_record' => $book, 'book_of_record_changed_at' => now(), 'book_of_record_changed_by' => $actor->id]);
        }

        return $s->fresh();
    }

    /** The connection that is the books, when it is an external one and connected. */
    public function bookConnection(int $companyId): ?AccountingConnection
    {
        $book = $this->bookOfRecord($companyId);
        if ($book === 'internal') {
            return null;
        }
        $c = AccountingConnection::withoutGlobalScopes()->where('company_id', $companyId)->where('provider', $book)->first();

        return $c && $c->isConnected() ? $c : null;
    }

    /**
     * A farm journal was just posted (AgriLedger). With external books it is queued for export once
     * the posting commits; with our own ledger nothing happens.
     */
    public function journalPosted(FinJournal $j): void
    {
        $companyId = (int) $j->company_id;
        if (! $companyId || $this->bookOfRecord($companyId) === 'internal') {
            return;
        }
        DB::afterCommit(function () use ($companyId, $j) {
            if (($c = $this->bookConnection($companyId)) && $c->options()['push_journals'] && $this->isFarmJournal($j->id)) {
                $this->queue($c, 'journal', $j->id, (string) $j->journal_no);
            }
        });
    }

    /** Everything due out (farm invoices, bills and journals since the sync start) queued and pushed; payments pulled back. */
    public function run(int $companyId): array
    {
        $c = $this->bookConnection($companyId);
        if (! $c) {
            return ['queued' => 0, 'pushed' => 0, 'failed' => 0, 'payments' => 0, 'note' => 'The books are our own ledger; nothing to sync.'];
        }
        $out = $this->pushDue($c);
        $error = null;
        try {
            $out['payments'] = $c->options()['pull_payments'] ? $this->pullPayments($c) : 0;
        } catch (\Throwable $x) {
            $out['payments'] = 0;
            $error = 'Payments could not be read: '.mb_strimwidth($x->getMessage(), 0, 400);
        }
        $c->forceFill(['last_sync_at' => now(), 'last_error' => $error ?? ($out['failed'] ? "{$out['failed']} record(s) failed to push; see the sync log." : null)])->saveQuietly();
        $out['error'] = $error;

        return $out;
    }

    /** @return array{queued: int, pushed: int, failed: int} */
    public function pushDue(AccountingConnection $c): array
    {
        $o = $c->options();
        $from = $this->syncFrom($c);
        $byDate = ! empty($o['sync_from']);
        $queued = $pushed = $failed = 0;
        $done = AccountingLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('direction', 'out')->where('status', 'synced')
            ->get(['record_type', 'local_id'])->groupBy('record_type')->map(fn ($g) => $g->pluck('local_id')->all());

        foreach ($this->farmInvoices((int) $c->company_id, $from, $byDate) as $inv) {
            $type = $inv->direction === 'payable' ? 'bill' : 'invoice';
            if (! $o[$type === 'bill' ? 'push_bills' : 'push_invoices'] || in_array($inv->id, $done[$type] ?? [], true)) {
                continue;
            }
            $e = $this->queue($c, $type, $inv->id, (string) $inv->number);
            $queued++;
            $e->status === 'processed' ? $pushed++ : $failed++;
        }
        if ($o['push_journals']) {
            foreach ($this->farmJournals((int) $c->company_id, $from, $byDate) as $j) {
                if (in_array($j->id, $done['journal'] ?? [], true)) {
                    continue;
                }
                $e = $this->queue($c, 'journal', $j->id, (string) $j->journal_no);
                $queued++;
                $e->status === 'processed' ? $pushed++ : $failed++;
            }
        }

        return ['queued' => $queued, 'pushed' => $pushed, 'failed' => $failed];
    }

    /** One record out, as an integration message (a repeat is answered from the first, a failure waits for a retry). */
    public function queue(AccountingConnection $c, string $type, int $localId, string $ref): IntegrationEvent
    {
        $link = AccountingLink::withoutGlobalScopes()->firstOrCreate(['connection_id' => $c->id, 'record_type' => $type, 'local_id' => $localId, 'direction' => 'out'],
            ['company_id' => $c->company_id, 'local_ref' => mb_strimwidth($ref, 0, 120), 'status' => 'pending']);
        ['event' => $e] = app(Integrations::class)->receive((int) $c->company_id, 'accounting_push', "{$c->provider}:{$type}:{$localId}",
            ['connection_id' => $c->id, 'provider' => $c->provider, 'type' => $type, 'local_id' => $localId, 'ref' => $ref]);
        if ((int) $link->event_id !== (int) $e->id) {
            $link->forceFill(['event_id' => $e->id])->saveQuietly();
        }

        return $e;
    }

    /**
     * The push handler. A record that already has its external id is not sent again — that is what
     * makes the scheduler's retries and an administrator's retry safe.
     */
    public function push(array $p, IntegrationEvent $e): array
    {
        $c = AccountingConnection::withoutGlobalScopes()->where('company_id', $e->company_id)->findOrFail($p['connection_id'] ?? 0);
        $link = AccountingLink::withoutGlobalScopes()->firstOrCreate(['connection_id' => $c->id, 'record_type' => $p['type'], 'local_id' => $p['local_id'], 'direction' => 'out'],
            ['company_id' => $c->company_id, 'local_ref' => $p['ref'] ?? null, 'status' => 'pending']);
        if ($link->external_id) {
            return ['external_id' => $link->external_id, 'already' => true];
        }
        $link->forceFill(['event_id' => $e->id, 'attempts' => $link->attempts + 1])->saveQuietly();
        try {
            if (! $c->isConnected()) {
                throw new AccountingApiError(config("agri.accounting.providers.{$c->provider}.name").' is not connected.');
            }
            $connector = $this->connections->connector($c);
            $this->documents->forget();
            $key = 'mkhaya-'.$c->company_id.'-'.$p['type'].'-'.$p['local_id'];
            if ($p['type'] === 'journal') {
                $j = FinJournal::withoutGlobalScopes()->where('company_id', $c->company_id)->findOrFail($p['local_id']);
                $r = $connector->pushJournal($this->documents->journal($c, $j), $key);
            } else {
                $inv = FinInvoice::withoutGlobalScopes()->where('company_id', $c->company_id)->findOrFail($p['local_id']);
                $contact = $this->contactFor($c, $connector, (int) $inv->party_id, $inv->direction === 'payable' ? 'supplier' : 'customer');
                $r = $connector->pushInvoice($this->documents->invoice($c, $inv, $contact), $key);
            }
        } catch (\Throwable $x) {
            $link->forceFill(['status' => 'failed', 'last_error' => mb_strimwidth($x->getMessage(), 0, 1000)])->saveQuietly();
            throw $x;
        }
        $link->forceFill(['status' => 'synced', 'external_id' => $r['id'], 'external_ref' => $r['number'] ?? null, 'last_error' => null, 'synced_at' => now()])->saveQuietly();

        return ['external_id' => $r['id'], 'external_ref' => $r['number'] ?? null];
    }

    /** Payments recorded in the external system since the last pull, each allocation queued to be applied here once. */
    public function pullPayments(AccountingConnection $c): int
    {
        $since = $c->payments_pulled_at ?? $this->syncFrom($c);
        $started = now();
        $n = 0;
        foreach ($this->connections->connector($c)->payments($since) as $pay) {
            foreach ($pay['allocations'] as $a) {
                $link = AccountingLink::withoutGlobalScopes()->where('connection_id', $c->id)->whereIn('record_type', ['invoice', 'bill'])
                    ->where('direction', 'out')->where('external_id', $a['document_id'])->first();
                if (! $link || $a['amount'] <= 0) {
                    continue;   // a payment for something the farm did not send
                }
                app(Integrations::class)->receive((int) $c->company_id, 'accounting_pull', mb_strimwidth("{$c->provider}:payment:{$pay['id']}:{$a['document_id']}", 0, 160),
                    ['connection_id' => $c->id, 'payment_id' => $pay['id'], 'document_id' => $a['document_id'], 'invoice_id' => $link->local_id,
                        'amount' => round((float) $a['amount'], 2), 'date' => $pay['date'], 'reference' => $pay['reference'] ?? null]);
                $n++;
            }
        }
        $c->forceFill(['payments_pulled_at' => $started])->saveQuietly();

        return $n;
    }

    /** The pull handler: the payment recorded against our invoice or bill, up to what is still owed. */
    public function applyPayment(array $p, IntegrationEvent $e): array
    {
        $c = AccountingConnection::withoutGlobalScopes()->where('company_id', $e->company_id)->findOrFail($p['connection_id'] ?? 0);
        $inv = FinInvoice::withoutGlobalScopes()->where('company_id', $c->company_id)->findOrFail($p['invoice_id'] ?? 0);
        $key = mb_strimwidth($p['payment_id'].':'.$p['document_id'], 0, 120);
        $seen = AccountingLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('record_type', 'payment')->where('direction', 'in')->where('external_id', $key)->first();
        if ($seen) {
            return ['payment' => $seen->local_id, 'already' => true];
        }
        $amount = round(min((float) $p['amount'], $inv->outstanding()), 2);
        if ($amount <= 0) {
            return ['skipped' => "{$inv->number} is already paid."];
        }
        $name = config("agri.accounting.providers.{$c->provider}.name");
        $payment = app(ArApService::class)->recordPayment(['party_id' => $inv->party_id, 'direction' => $inv->direction === 'payable' ? 'payment' : 'receipt',
            'payment_date' => $p['date'] ?? today()->toDateString(), 'reference' => mb_strimwidth("{$name} ".($p['reference'] ?: $p['payment_id']), 0, 100, ''),
            'allocations' => [['invoice_id' => $inv->id, 'amount' => $amount]]]);
        AccountingLink::withoutGlobalScopes()->create(['company_id' => $c->company_id, 'connection_id' => $c->id, 'record_type' => 'payment', 'local_id' => $payment->id,
            'local_ref' => $payment->number, 'direction' => 'in', 'status' => 'synced', 'external_id' => $key, 'external_ref' => $p['reference'] ?? null,
            'event_id' => $e->id, 'attempts' => 1, 'synced_at' => now()]);

        return ['payment' => $payment->number, 'invoice' => $inv->number, 'amount' => $amount, 'status' => $inv->fresh()->status];
    }

    /** Their chart, tax rates, tracking and items, kept on the connection for the mapping screen; accounts with the same code map themselves. */
    public function pullCatalog(AccountingConnection $c): array
    {
        $catalog = $this->connections->connector($c)->catalog() + ['pulled_at' => now()->toIso8601String()];
        $c->forceFill(['catalog' => $catalog, 'last_error' => null])->save();
        $mapped = 0;
        $ours = DB::table('fin_accounts')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $c->company_id))->where('is_postable', true)->pluck('name', 'code');
        foreach ($catalog['accounts'] as $a) {
            if ($a['code'] && isset($ours[$a['code']])) {
                $m = AccountingMapping::withoutGlobalScopes()->firstOrNew(['connection_id' => $c->id, 'kind' => 'account', 'local_key' => (string) $a['code']]);
                if (! $m->exists) {
                    $m->fill(['company_id' => $c->company_id, 'local_label' => $ours[$a['code']], 'external_id' => $a['id'], 'external_code' => $a['code'], 'external_name' => $a['name'],
                        'extra' => ['matched_by' => 'code']])->save();
                    $mapped++;
                }
            }
        }

        return ['accounts' => count($catalog['accounts']), 'tax_rates' => count($catalog['tax_rates']), 'tracking' => count($catalog['tracking']), 'auto_mapped' => $mapped];
    }

    /**
     * The farm's customers and suppliers matched to theirs: by VAT number first, then by name.
     * Matching only; a party with no match is created there the first time a document for it goes.
     */
    public function matchContacts(AccountingConnection $c): array
    {
        $theirs = collect($this->connections->connector($c)->contacts());
        $byVat = $theirs->filter(fn ($x) => ! empty($x['tax_number']))->keyBy(fn ($x) => self::vat($x['tax_number']));
        $byName = $theirs->keyBy(fn ($x) => mb_strtolower(trim($x['name'])));
        $matched = 0;
        $parties = FinParty::withoutGlobalScopes()->where('company_id', $c->company_id)
            ->whereIn('id', $this->farmInvoices((int) $c->company_id, null)->pluck('party_id')->unique())->get();
        foreach ($parties as $party) {
            if (AccountingMapping::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', 'contact')->where('local_key', (string) $party->id)->exists()) {
                continue;
            }
            $hit = ($party->vat_number ? $byVat->get(self::vat($party->vat_number)) : null);
            $by = $hit ? 'vat_number' : 'name';
            $hit ??= $byName->get(mb_strtolower(trim($party->name)));
            if ($hit) {
                AccountingMapping::withoutGlobalScopes()->create(['company_id' => $c->company_id, 'connection_id' => $c->id, 'kind' => 'contact', 'local_key' => (string) $party->id,
                    'local_label' => $party->name, 'external_id' => $hit['id'], 'external_name' => $hit['name'], 'extra' => ['matched_by' => $by]]);
                $matched++;
            }
        }

        return ['theirs' => $theirs->count(), 'ours' => $parties->count(), 'matched' => $matched];
    }

    /** @param list<array{kind: string, local_key: string, local_label?: ?string, external_id?: ?string, external_code?: ?string, external_name?: ?string, extra?: ?array}> $rows */
    public function saveMappings(AccountingConnection $c, array $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            if (! in_array($r['kind'] ?? '', AccountingMapping::KINDS, true) || blank($r['local_key'] ?? null)) {
                continue;
            }
            $m = AccountingMapping::withoutGlobalScopes()->firstOrNew(['connection_id' => $c->id, 'kind' => $r['kind'], 'local_key' => (string) $r['local_key']]);
            if (blank($r['external_id'] ?? null) && blank($r['external_code'] ?? null)) {
                $m->exists && $m->delete();

                continue;
            }
            $m->fill(['company_id' => $c->company_id, 'local_label' => $r['local_label'] ?? $m->local_label, 'external_id' => $r['external_id'] ?? null,
                'external_code' => $r['external_code'] ?? null, 'external_name' => $r['external_name'] ?? null, 'extra' => $r['extra'] ?? $m->extra])->save();
            $n++;
        }

        return $n;
    }

    /** Farm sales invoices, settlements and purchase bills: carrying a farm dimension themselves or on their posting. */
    public function farmInvoices(int $companyId, ?Carbon $from, bool $byDate = true)
    {
        return FinInvoice::withoutGlobalScopes()->where('company_id', $companyId)->whereNotIn('status', ['void', 'draft', 'cancelled'])
            ->where(fn ($q) => $q->whereNull('doc_type')->orWhere('doc_type', 'invoice'))
            ->when($from, fn ($q) => $byDate ? $q->whereDate('invoice_date', '>=', $from->toDateString()) : $q->where('created_at', '>=', $from))
            ->where(fn ($q) => $q->whereRaw("dimensions->>'agri_farm' IS NOT NULL")
                ->orWhereExists(fn ($e) => $e->from('fin_journal_lines as l')->whereColumn('l.journal_id', 'fin_invoices.journal_id')->whereRaw("l.dimensions->>'agri_farm' IS NOT NULL")))
            ->orderBy('id')->limit(500)->get();
    }

    /** Farm journals that are not an invoice's or a payment's own posting (those go as the document). */
    public function farmJournals(int $companyId, ?Carbon $from, bool $byDate = true)
    {
        return FinJournal::withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'draft')
            ->when($from, fn ($q) => $byDate ? $q->whereDate('journal_date', '>=', $from->toDateString()) : $q->where('created_at', '>=', $from))
            ->whereExists(fn ($e) => $e->from('fin_journal_lines as l')->whereColumn('l.journal_id', 'fin_journals.id')->whereRaw("l.dimensions->>'agri_farm' IS NOT NULL"))
            ->whereNotExists(fn ($e) => $e->from('fin_invoices as i')->whereColumn('i.journal_id', 'fin_journals.id'))
            ->whereNotExists(fn ($e) => $e->from('fin_payments as p')->whereColumn('p.journal_id', 'fin_journals.id'))
            ->orderBy('id')->limit(500)->get();
    }

    public function isFarmJournal(int $journalId): bool
    {
        return DB::table('fin_journal_lines')->where('journal_id', $journalId)->whereRaw("dimensions->>'agri_farm' IS NOT NULL")->exists()
            && ! DB::table('fin_invoices')->where('journal_id', $journalId)->exists() && ! DB::table('fin_payments')->where('journal_id', $journalId)->exists();
    }

    /**
     * From when farm records go out: the connection's own start date (records dated from it), else
     * the moment the books moved there (records made from then on — what was already in the books
     * before the switch is the accountant's opening position, not ours to resend).
     */
    public function syncFrom(AccountingConnection $c): Carbon
    {
        $o = $c->options();
        if (! empty($o['sync_from'])) {
            return Carbon::parse($o['sync_from'])->startOfDay();
        }
        $changed = AgriOrgSetting::for((int) $c->company_id)->getRawOriginal('book_of_record_changed_at');

        return $changed ? Carbon::parse($changed, config('app.timezone')) : now();
    }

    /** Our party as a contact there: mapped already, found by VAT number or name, or created. */
    private function contactFor(AccountingConnection $c, Connector $connector, int $partyId, string $kind): string
    {
        $m = AccountingMapping::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', 'contact')->where('local_key', (string) $partyId)->first();
        if ($m?->external_id) {
            return $m->external_id;
        }
        $party = FinParty::withoutGlobalScopes()->where('company_id', $c->company_id)->findOrFail($partyId);
        $found = $connector->findContact((string) $party->name, $party->vat_number ?: null, $kind);
        $by = $found['matched_by'] ?? 'created';
        $id = $found['id'] ?? $connector->createContact(['name' => (string) $party->name, 'email' => $party->email, 'tax_number' => $party->vat_number], $kind)['id'];
        AccountingMapping::withoutGlobalScopes()->updateOrCreate(['connection_id' => $c->id, 'kind' => 'contact', 'local_key' => (string) $partyId],
            ['company_id' => $c->company_id, 'local_label' => $party->name, 'external_id' => $id, 'external_name' => $found['name'] ?? $party->name, 'extra' => ['matched_by' => $by]]);

        return $id;
    }

    private static function vat(?string $v): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $v));
    }
}
