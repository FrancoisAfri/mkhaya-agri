<?php

namespace App\Modules\Tenancy\Services;

/**
 * Which edition this instance runs as. SaaS = multi-tenant with subscriptions;
 * self-hosted = one customer whose entitlements come from a signed license file.
 */
class Edition
{
    public static function current(): string
    {
        return config('edition.edition', 'saas');
    }

    public static function isSaas(): bool
    {
        return self::current() === 'saas';
    }

    public static function isSelfHosted(): bool
    {
        return self::current() === 'self_hosted';
    }
}
