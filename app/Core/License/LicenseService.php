<?php

namespace App\Core\License;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * Tamper-resistant licensing. A license is a JSON payload (client, domain,
 * server fingerprint, allowed modules, expiry) signed with an Ed25519 PRIVATE
 * key that only the vendor holds. The app verifies it with the embedded PUBLIC
 * key, so editing the database `active` flag no longer grants a module — only a
 * validly-signed license that names the module does.
 *
 * Licensing is DORMANT unless a public key is configured, so installs without
 * licensing set up behave exactly as before.
 *
 * NOTE: a plaintext check like this is only as strong as the code around it —
 * to be truly tamper-proof the gate must be encrypted (ionCube/SourceGuardian)
 * or run off the client's server. This is the cryptographic foundation that
 * makes those layers enforceable.
 */
class LicenseService
{
    /** Memoised verification result for this process. */
    private ?array $status = null;

    /** Canonical bytes that get signed/verified — issuer and verifier must agree exactly. */
    public static function canonical(array $payload): string
    {
        $sort = function (&$value) use (&$sort) {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as &$v) {
                    $sort($v);
                }
            }
        };
        $sort($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** True once a public key is present — otherwise licensing stays dormant. */
    public function licensingActive(): bool
    {
        return (bool) config('license.enforce', true) && trim((string) config('license.public_key')) !== '';
    }

    /** Stable per-server fingerprint the license is bound to. */
    public function fingerprint(): string
    {
        $source = config('license.server_id');

        if (! $source) {
            $machineId = @file_get_contents('/etc/machine-id');
            $source = $machineId !== false && trim($machineId) !== '' ? trim($machineId) : gethostname();
        }

        return hash('sha256', 'mkhaya-server|' . $source);
    }

    /** Verify the license and cache the verdict for this request. */
    public function status(): array
    {
        if ($this->status !== null) {
            return $this->status;
        }

        // Dormant: no public key configured → allow everything.
        if (! $this->licensingActive()) {
            return $this->status = ['valid' => true, 'enforced' => false, 'reason' => 'licensing-disabled', 'payload' => null];
        }

        $deny = fn (string $reason) => $this->status = ['valid' => false, 'enforced' => true, 'reason' => $reason, 'payload' => null];

        $path = config('license.path');
        if (! is_string($path) || ! is_file($path)) {
            return $deny('license-missing');
        }

        $raw = json_decode((string) @file_get_contents($path), true);
        if (! is_array($raw) || ! isset($raw['payload'], $raw['signature']) || ! is_array($raw['payload'])) {
            return $deny('license-malformed');
        }

        // 1) Signature must verify against the embedded public key.
        $publicKey = base64_decode((string) config('license.public_key'), true);
        $signature = base64_decode((string) $raw['signature'], true);
        if ($publicKey === false || $signature === false
            || ! sodium_crypto_sign_verify_detached($signature, self::canonical($raw['payload']), $publicKey)) {
            return $deny('signature-invalid');
        }

        $p = $raw['payload'];

        // 2) Validity window (with a basic clock-rollback guard).
        $now = time();
        if (! empty($p['issued_at']) && $now + 86400 < strtotime($p['issued_at'])) {
            return $deny('clock-rollback');
        }
        if (! empty($p['expires_at']) && $now > strtotime($p['expires_at'])) {
            return $deny('expired');
        }

        // 3) Domain binding.
        if (! empty($p['domain']) && ! $this->hostMatches($p['domain'])) {
            return $deny('domain-mismatch');
        }

        // 4) Server fingerprint binding.
        if (! empty($p['fingerprint']) && ! hash_equals((string) $p['fingerprint'], $this->fingerprint())) {
            return $deny('fingerprint-mismatch');
        }

        return $this->status = ['valid' => true, 'enforced' => true, 'reason' => 'ok', 'payload' => $p];
    }

    public function valid(): bool
    {
        return $this->status()['valid'];
    }

    /** Whether a given module code is permitted by the active license. */
    public function allows(string $moduleCode): bool
    {
        $status = $this->status();

        if (! $status['enforced']) {
            return true; // dormant
        }
        if (! $status['valid']) {
            return false; // hard-stop
        }
        if (! $this->remoteOk()) {
            return false; // remotely revoked, or offline past the grace window
        }

        $modules = $status['payload']['modules'] ?? [];
        return in_array('*', $modules, true) || in_array($moduleCode, $modules, true);
    }

    /**
     * Remote kill-switch verdict (cached by the license:heartbeat command).
     * Explicit revoke → immediate hard-stop. Authority simply unreachable → keep
     * working until grace_hours since the last successful check, then stop (so a
     * vendor-side outage doesn't instantly take every client down).
     */
    private function remoteOk(): bool
    {
        if (! config('license.remote') || ! config('license.server_url')) {
            return true; // remote validation disabled
        }

        $state = Cache::get('license_remote_state');

        // First enforcement: start the grace clock so a fresh enable isn't bricked
        // before the first heartbeat runs.
        if (! is_array($state)) {
            Cache::forever('license_remote_state', ['checked_at' => time(), 'revoked' => false]);
            return true;
        }

        if (! empty($state['revoked'])) {
            return false;
        }

        $graceSeconds = (int) config('license.grace_hours', 72) * 3600;
        return (time() - (int) ($state['checked_at'] ?? 0)) <= $graceSeconds;
    }

    /**
     * Service/policy-level guard: abort if the module isn't licensed. Use inside
     * business logic, queued jobs, scheduled commands and API handlers — anywhere
     * the route middleware doesn't run — so the gate isn't only at the edge.
     */
    public function requires(string $moduleCode): void
    {
        if (! $this->allows($moduleCode)) {
            abort(403, "This module ({$moduleCode}) is not covered by your licence.");
        }
    }

    /** A short, non-secret instance watermark for leak tracing (see WatermarkInstance). */
    public function watermark(): string
    {
        $p = $this->status()['payload'] ?? [];
        $client = $p['client'] ?? 'unlicensed';

        return substr(hash('sha256', $client . '|' . $this->fingerprint()), 0, 16);
    }

    /** Compare a licensed domain against the current host (normalised). */
    private function hostMatches(string $licensed): bool
    {
        $current = RequestFacade::getHost() ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $norm = fn ($h) => strtolower(preg_replace('/^www\./i', '', (string) $h));

        return $norm($licensed) === $norm($current);
    }
}
