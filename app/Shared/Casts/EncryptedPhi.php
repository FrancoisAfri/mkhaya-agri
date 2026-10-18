<?php

namespace App\Shared\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast that transparently encrypts PHI fields at rest.
 *
 * Uses Laravel's app-key encryption (AES-256-CBC via encrypt()/decrypt()).
 * Silently returns null on decryption failure so that migrated-but-not-yet-
 * re-encrypted legacy records don't crash — instead they surface as null
 * and trigger a re-save on next write.
 */
class EncryptedPhi implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Throwable) {
            // Legacy plain-text value — return as-is so it can be re-saved encrypted
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return encrypt((string) $value);
    }
}
