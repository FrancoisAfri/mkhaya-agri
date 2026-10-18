<?php

namespace App\Modules\SupplierManagement\DueDiligence\Data;

/**
 * Whether a configured provider is actually usable right now — the answer the Data Source Registry
 * screen exists to show. "Connected" in a registry table that never tested the connection is the
 * kind of reassurance that costs you an audit.
 */
class ProviderHealth
{
    public function __construct(
        public readonly bool $configured,
        public readonly bool $reachable,
        public readonly ?string $message = null,
        public readonly ?int $quotaRemaining = null,
    ) {}

    public static function notConfigured(string $message = 'No credentials configured.'): self
    {
        return new self(false, false, $message);
    }

    public static function ok(?string $message = null, ?int $quotaRemaining = null): self
    {
        return new self(true, true, $message, $quotaRemaining);
    }

    public static function failing(string $message): self
    {
        return new self(true, false, $message);
    }

    /** One word for the UI: connected | failing | not_configured. */
    public function state(): string
    {
        return match (true) {
            ! $this->configured => 'not_configured',
            $this->reachable => 'connected',
            default => 'failing',
        };
    }
}
