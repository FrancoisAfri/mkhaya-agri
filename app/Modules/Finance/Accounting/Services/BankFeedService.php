<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinBankFeed;
use Illuminate\Support\Facades\Auth;

/**
 * Bank feeds: link a bank account to Yodlee and pull its transactions in as statement lines.
 *
 * ── There is no provider to choose ──────────────────────────────────────────
 *
 * This used to offer four. Three of them — Salt Edge, Stitch, and a synthesised "demo" feed — were
 * either unimplemented or fictional, so the dropdown asked the user to make a decision where only
 * one answer ever worked. Yodlee is the product's bank data provider; asking is just a way to get
 * it wrong.
 *
 * The demo feed is gone rather than hidden. It wrote transactions indistinguishable from real ones
 * into books people file tax on, and a switch that turns that on is a switch somebody eventually
 * flips on the wrong environment.
 *
 * The `provider` column stays on the table because historical rows carry it and it costs nothing —
 * but nothing sets it to anything but yodlee, and nothing reads it to decide behaviour.
 */
class BankFeedService
{
    public const PROVIDER_YODLEE = 'yodlee';

    public function __construct(private YodleeFeedProvider $yodlee)
    {
    }

    public function connect(array $data): FinBankFeed
    {
        return FinBankFeed::create([
            'bank_account_id'       => $data['bank_account_id'],
            'provider'              => self::PROVIDER_YODLEE,
            'import_from'           => $data['import_from'] ?? now()->subMonth()->toDateString(),
            'refresh_mode'          => $data['refresh_mode'] ?? 'daily',
            'status'                => 'connected',
            'provider_account_id'   => $data['provider_account_id'] ?? null,
            'provider_ref'          => $data['provider_ref'] ?? null,
            'provider_account_name' => $data['provider_account_name'] ?? null,
            'provider_account_mask' => $data['provider_account_mask'] ?? null,
            'external_ref'          => $data['provider_ref'] ?? null,
            'created_by'            => Auth::id(),
        ]);
    }

    public function disconnect(FinBankFeed $feed): void
    {
        $feed->update(['status' => 'disconnected']);
    }

    /** Pull new transactions since the last sync and import them; returns the count. */
    public function refresh(FinBankFeed $feed): int
    {
        if ($feed->status === 'disconnected') {
            return 0;
        }

        /*
         * A feed left on a provider we no longer run says so and stops.
         *
         * Only reachable for rows created before this became Yodlee-only. Quietly treating one as a
         * Yodlee feed would claim a bank link nobody authorised, and it has no Yodlee account id to
         * pull from anyway — so it would fail one step later with a worse message.
         */
        if ($feed->provider !== self::PROVIDER_YODLEE) {
            $feed->update([
                'status'     => 'error',
                'last_error' => "The '{$feed->provider}' connector was removed. Reconnect this account through Yodlee.",
            ]);

            return 0;
        }

        return $this->yodlee->refresh($feed);
    }
}
