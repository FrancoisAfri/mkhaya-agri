<?php

namespace App\Modules\HR\Recruitment\Services;

use App\Modules\HR\Recruitment\Models\SocialAccount;
use App\Support\Social\ConnectedAccountStore;
use Illuminate\Support\Carbon;

/**
 * Writes a connected network onto a recruitment social account.
 *
 * Connecting the same network twice replaces the tokens on the existing row rather than
 * adding a second one, so a reconnect after an expired token leaves one account and its
 * publishing history intact.
 */
class RecruitmentAccountStore implements ConnectedAccountStore
{
    public function store(string $platform, array $account): void
    {
        $credentials = $account['credentials'];

        $expiresAt = isset($credentials['expires_at'])
            ? Carbon::parse($credentials['expires_at'])
            : null;

        SocialAccount::updateOrCreate(
            ['provider' => $platform],
            [
                'display_name'     => $account['account_name'],
                'access_token'     => $credentials['access_token'] ?? null,
                'refresh_token'    => $credentials['refresh_token'] ?? null,
                'token_expires_at' => $expiresAt,
                'meta'             => array_filter([
                    'external_id' => $account['external_id'],
                    // Facebook posts to a Page, and the Page token is not the user's token.
                    'page_id'     => $credentials['page_id'] ?? null,
                ]),
                'is_active'        => true,
                'created_by'       => auth()->id() ?? 0,
            ],
        );
    }

    public function landingRoute(): string
    {
        return 'recruitment.settings.social-accounts.index';
    }
}
