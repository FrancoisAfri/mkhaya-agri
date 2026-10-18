<?php

namespace App\Support\Social;

use App\Modules\HR\Recruitment\Services\RecruitmentAccountStore;

/**
 * The modules that can start a social connection, by the key carried through the OAuth
 * round trip in the session.
 */
class ConnectionDestinations
{
    private const STORES = [
        'recruitment'       => RecruitmentAccountStore::class,
    ];

    public const DEFAULT = 'recruitment';

    public function knows(?string $destination): bool
    {
        return $destination !== null && isset(self::STORES[$destination]);
    }

    /** Falls back to Content Marketing, which was the only destination before Recruitment. */
    public function store(?string $destination): ConnectedAccountStore
    {
        return app(self::STORES[$destination] ?? self::STORES[self::DEFAULT]);
    }
}
