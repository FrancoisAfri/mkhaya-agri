<?php

namespace App\Modules\Procurement\Governance\Support;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Sealing and unsealing a bid.
 *
 * Kept to one small class on purpose: everything security-sensitive about bid intake happens here,
 * so it can be read end to end by someone deciding whether to trust it, rather than reconstructed
 * from encryption scattered through a service.
 *
 * **What this guarantees.** A bid is stored as AES-256-GCM ciphertext under a key generated per
 * tender, so reading the database does not hand over every competitor's price. Each payload carries
 * a SHA-256 digest of its plaintext, stored outside the ciphertext, so a payload swapped or edited
 * in the database fails verification at opening instead of being scored.
 *
 * **What it does not guarantee, and nobody should claim it does.** The envelope key is encrypted
 * under the application key, so an actor holding both the database and APP_KEY — a platform
 * administrator, or anyone who has compromised the host — can decrypt before the tender closes.
 * Making early opening *impossible* rather than *evident* requires the key to live somewhere the
 * application cannot reach it unaided: a KMS or HSM, or keys held by the bidders. That is a
 * deployment decision. What is guaranteed here is that opening is a recorded, two-person ceremony,
 * and that tampering with a sealed bid is detectable afterwards.
 */
class BidSeal
{
    private const CIPHER = 'aes-256-gcm';

    /** A fresh envelope key for one tender, returned raw for immediate encryption under APP_KEY. */
    public function generateKey(): string
    {
        return Encrypter::generateKey(self::CIPHER);
    }

    /** Protect an envelope key for storage. */
    public function wrapKey(string $key): string
    {
        return Crypt::encryptString(base64_encode($key));
    }

    public function unwrapKey(string $ciphertext): string
    {
        try {
            return base64_decode(Crypt::decryptString($ciphertext), true) ?: '';
        } catch (\Throwable $e) {
            throw DomainRuleException::forField(
                'seal',
                __('procurement_governance.rules.seal.key_unavailable'),
            );
        }
    }

    /**
     * Seal a bid.
     *
     * The digest is taken over a canonical encoding of the plaintext rather than the ciphertext:
     * ciphertext changes on every encryption, so hashing it would prove only that nobody re-encrypted
     * the same data, which is not the claim anyone needs.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ciphertext: string, digest: string}
     */
    public function seal(string $key, array $payload): array
    {
        $plaintext = $this->canonical($payload);

        return [
            'ciphertext' => $this->encrypter($key)->encryptString($plaintext),
            'digest' => hash('sha256', $plaintext),
        ];
    }

    /**
     * Open a sealed bid, refusing if it is not the bid that was sealed.
     *
     * @return array<string, mixed>
     *
     * @throws DomainRuleException when the payload cannot be decrypted or fails its digest
     */
    public function unseal(string $key, string $ciphertext, string $expectedDigest): array
    {
        try {
            $plaintext = $this->encrypter($key)->decryptString($ciphertext);
        } catch (\Throwable $e) {
            throw DomainRuleException::forField(
                'seal',
                __('procurement_governance.rules.seal.decrypt_failed'),
            );
        }

        if (! hash_equals($expectedDigest, hash('sha256', $plaintext))) {
            throw DomainRuleException::forField(
                'seal',
                __('procurement_governance.rules.seal.digest_mismatch'),
            );
        }

        $payload = json_decode($plaintext, true);

        if (! is_array($payload)) {
            throw DomainRuleException::forField('seal', __('procurement_governance.rules.seal.not_a_bid'));
        }

        return $payload;
    }

    /** Encrypt document bytes under the same envelope key — a bid document usually restates the price. */
    public function sealBytes(string $key, string $bytes): string
    {
        return $this->encrypter($key)->encryptString($bytes);
    }

    public function unsealBytes(string $key, string $ciphertext): string
    {
        try {
            return $this->encrypter($key)->decryptString($ciphertext);
        } catch (\Throwable $e) {
            throw DomainRuleException::forField('seal', __('procurement_governance.rules.seal.document_decrypt_failed'));
        }
    }

    private function encrypter(string $key): Encrypter
    {
        return new Encrypter($key, self::CIPHER);
    }

    /**
     * A stable encoding of the payload.
     *
     * Keys are sorted so that two identical bids hash identically regardless of the order the form
     * happened to send its fields in — otherwise the digest would depend on an accident of the
     * client, and a re-verification months later could fail for no real reason.
     *
     * @param  array<string, mixed>  $payload
     */
    private function canonical(array $payload): string
    {
        ksort($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
