<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinBankFeed;
use App\Modules\Finance\Accounting\Models\FinBankStatementLine;
use App\Modules\Finance\Accounting\Models\FinYodleeSetting;
use App\Modules\Finance\Accounting\Models\FinYodleeUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a Yodlee account's transactions into bank statement lines.
 *
 * ── Overlap on purpose ──────────────────────────────────────────────────────
 *
 * Each pull re-reads a few days either side of where the last one finished. Banks post transactions
 * late — a card purchase on Friday can appear on Tuesday, dated Friday — so a feed that asked only
 * for "since last time" would skip it permanently. Re-reading is safe because every line is
 * de-duplicated on a hash, which is the same guard the CSV import and the demo feed use.
 *
 * ── Sign convention ─────────────────────────────────────────────────────────
 *
 * Yodlee reports an amount as a positive number plus a baseType of DEBIT or CREDIT. Our statement
 * lines are signed: money out is negative. Taking Yodlee's amount at face value would file every
 * payment as income and reconcile the month to roughly double the real balance.
 */
class YodleeFeedProvider
{
    /**
     * How far back each refresh re-reads.
     *
     * Five days covers a weekend plus the usual settlement delay. Longer costs nothing in
     * correctness — the hash catches repeats — but it does cost API calls against a metered plan.
     */
    private const OVERLAP_DAYS = 5;

    public function __construct(private readonly ReconciliationService $recon)
    {
    }

    /**
     * Pull this feed's new transactions. Returns how many statement lines were created.
     */
    public function refresh(FinBankFeed $feed): int
    {
        // The platform's subscription unless this tenant holds its own.
        $settings = FinYodleeSetting::resolve();

        if (! $settings || ! $settings->isConfigured()) {
            return $this->fail($feed, 'Bank feeds are not available yet — Yodlee has not been set up on this platform.');
        }

        if (blank($feed->provider_ref)) {
            return $this->fail($feed, 'This feed is not linked to a Yodlee account yet. Reconnect it and choose an account.');
        }

        $bank = $feed->bankAccount;

        if (! $bank) {
            return $this->fail($feed, 'The bank account this feed belongs to no longer exists.');
        }

        // Re-read from just before where the last pull finished — see the note on overlap above.
        $from = $feed->synced_through
            ? Carbon::parse($feed->synced_through)->subDays(self::OVERLAP_DAYS)
            : Carbon::parse($feed->import_from ?? now()->subMonths(3));
        $to = Carbon::today();

        try {
            // This company's own Yodlee identity: the subscription is shared, the user never is.
            $user = FinYodleeUser::forCurrentCompany($feed->company_id);

            $transactions = YodleeClient::for($settings, $user)->transactions($feed->provider_ref, $from, $to);
        } catch (\Throwable $e) {
            Log::warning('Yodlee refresh failed', [
                'feed' => $feed->id, 'account' => $feed->provider_ref, 'error' => $e->getMessage(),
            ]);

            return $this->fail($feed, Str::limit($e->getMessage(), 240));
        }

        $imported = 0;

        foreach ($transactions as $transaction) {
            if ($this->store($feed, $transaction)) {
                $imported++;
            }
        }

        $feed->update([
            'last_synced_at' => now(),
            'synced_through' => $to->toDateString(),
            'status'         => 'connected',
            'last_error'     => null,
        ]);

        if ($imported > 0) {
            // Anything matching a reconciliation rule is allocated straight away, so the user opens
            // the screen to the exceptions rather than to everything.
            $this->recon->applyRules($bank);
        }

        return $imported;
    }

    /**
     * One Yodlee transaction as a statement line. Returns false when we already had it.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function store(FinBankFeed $feed, array $transaction): bool
    {
        $date = $this->date($transaction);
        $amount = $this->signedAmount($transaction);

        if ($date === null || $amount === null) {
            return false;   // nothing usable — a pending authorisation with no amount yet
        }

        $description = Str::limit(trim(
            (string) ($transaction['description']['simple']
                ?? $transaction['description']['original']
                ?? $transaction['merchant']['name']
                ?? 'Bank transaction')
        ), 190, '');

        /*
         * Yodlee's own transaction id is the reference, which makes the hash stable across pulls
         * even when the bank later rewrites the description — a real behaviour on card transactions,
         * where "PENDING" becomes the merchant's name once it settles. Hashing the description
         * instead would import the same payment twice.
         */
        $reference = (string) ($transaction['id'] ?? $transaction['sourceId'] ?? '');
        $hash = md5($feed->bank_account_id . '|yodlee|' . ($reference !== ''
            ? $reference
            : $date->toDateString() . '|' . number_format($amount, 2, '.', '') . '|' . $description));

        if (FinBankStatementLine::where('bank_account_id', $feed->bank_account_id)->where('hash', $hash)->exists()) {
            return false;
        }

        FinBankStatementLine::create([
            'bank_account_id' => $feed->bank_account_id,
            'statement_date'  => $date->toDateString(),
            'description'     => $description,
            'reference'       => Str::limit($reference, 60, ''),
            'amount'          => $amount,
            'hash'            => $hash,
            'status'          => 'unmatched',
            'created_by'      => Auth::id(),
        ]);

        return true;
    }

    /**
     * The date the money moved.
     *
     * `date` is when the bank posted it; `transactionDate` is when it happened. Reconciliation
     * compares against a bank statement, and a statement shows the posting date — so that is the
     * one that must win where they differ.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function date(array $transaction): ?Carbon
    {
        $value = $transaction['date'] ?? $transaction['postDate'] ?? $transaction['transactionDate'] ?? null;

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Yodlee's positive amount plus its DEBIT/CREDIT flag, as one signed number.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function signedAmount(array $transaction): ?float
    {
        $amount = $transaction['amount']['amount'] ?? null;

        if ($amount === null || ! is_numeric($amount)) {
            return null;
        }

        $magnitude = abs((float) $amount);
        $isDebit = strtoupper((string) ($transaction['baseType'] ?? '')) === 'DEBIT';

        return round($isDebit ? -$magnitude : $magnitude, 2);
    }

    private function fail(FinBankFeed $feed, string $reason): int
    {
        $feed->update(['status' => 'error', 'last_error' => $reason]);

        return 0;
    }
}
