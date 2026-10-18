<?php

namespace App\Support\Social;

/**
 * Where a freshly connected social account gets written, and where the person lands after.
 *
 * Two modules now connect the same three networks, and each network's app is registered
 * with exactly one redirect URI. Giving Recruitment its own callback path would mean
 * re-registering every app with Facebook, X and LinkedIn — paperwork, in exchange for a
 * second copy of the same exchange code. So there is one callback, and this decides which
 * module the result belongs to.
 */
interface ConnectedAccountStore
{
    /**
     * Save the account the OAuth exchange resolved.
     *
     * @param  array{account_name:string, external_id:?string, credentials:array<string,mixed>}  $account
     */
    public function store(string $platform, array $account): void;

    /** Route name to return to, whether it worked or not. */
    public function landingRoute(): string;
}
