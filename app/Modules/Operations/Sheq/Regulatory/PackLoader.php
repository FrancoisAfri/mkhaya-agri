<?php

namespace App\Modules\Operations\Sheq\Regulatory;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a Regulatory Pack from disk into a manifest (§3.1, SHEQ-REG-8).
 *
 * A pack is authored as YAML — by a domain consultant, not an engineer (SHEQ-REG-10) — and compiled
 * here into the single immutable blob that gets stored against a version row. Splitting the source
 * across files (classifications/, obligations/, forms/) is supported so a large pack stays readable;
 * a single pack.yaml is equally valid and is what za-ohsa uses.
 *
 * Signature verification is enforced for packs that ship one. Verification is *not* skipped just
 * because a signature is absent — that would let an attacker strip the signature to bypass the
 * check — instead, absence is reported and the caller decides, so first-party packs can be
 * installed in development while `--require-signature` holds the line in production.
 */
class PackLoader
{
    public function __construct(private readonly ?string $publicKeyPath = null) {}

    /** Load every pack directory under the given root. @return list<PackManifest> */
    public function loadAll(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $packs = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir("{$root}/{$entry}")) {
                continue;
            }
            $packs[] = $this->load("{$root}/{$entry}");
        }

        return $packs;
    }

    public function load(string $directory): PackManifest
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("Regulatory pack directory not found: {$directory}");
        }

        $manifest = $this->readYaml($this->requireFile($directory, ['pack.yaml', 'pack.yml', 'pack.json']));

        // Optional split layout: merge sibling directories into the manifest under their own keys.
        foreach (['classifications' => 'list', 'obligations' => 'map', 'templates' => 'map'] as $section => $shape) {
            $path = "{$directory}/{$section}";
            if (! is_dir($path)) {
                continue;
            }

            $merged = $shape === 'list' ? array_values((array) ($manifest[$section] ?? [])) : (array) ($manifest[$section] ?? []);

            foreach (glob("{$path}/*.{yaml,yml}", GLOB_BRACE) ?: [] as $file) {
                $content = $this->readYaml($file);
                if ($shape === 'list') {
                    $merged[] = $content;
                } else {
                    $key = $content['id'] ?? pathinfo($file, PATHINFO_FILENAME);
                    $merged[$key] = $content;
                }
            }

            $manifest[$section] = $merged;
        }

        return PackManifest::fromArray($manifest);
    }

    /**
     * @return array{verified:bool,reason:?string}
     */
    public function verifySignature(string $directory, PackManifest $manifest): array
    {
        $signaturePath = "{$directory}/pack.sig";

        if (! is_file($signaturePath)) {
            return ['verified' => false, 'reason' => 'No pack.sig present.'];
        }

        if ($this->publicKeyPath === null || ! is_file($this->publicKeyPath)) {
            return ['verified' => false, 'reason' => 'No public key configured to verify against.'];
        }

        $key = openssl_pkey_get_public((string) file_get_contents($this->publicKeyPath));
        if ($key === false) {
            return ['verified' => false, 'reason' => 'Configured public key could not be read.'];
        }

        // Signed over the canonical manifest, not the raw bytes: whitespace or key order must not
        // invalidate an otherwise identical pack, and the checksum stored on the version row is
        // computed the same way.
        $ok = openssl_verify(
            PackManifest::canonicalJson($manifest->manifest),
            (string) base64_decode((string) file_get_contents($signaturePath), true),
            $key,
            OPENSSL_ALGO_SHA256
        );

        return $ok === 1
            ? ['verified' => true, 'reason' => null]
            : ['verified' => false, 'reason' => 'Signature does not match the pack contents.'];
    }

    /** @param list<string> $candidates */
    private function requireFile(string $directory, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_file("{$directory}/{$candidate}")) {
                return "{$directory}/{$candidate}";
            }
        }

        throw new RuntimeException(
            "Regulatory pack at {$directory} has no ".implode(' / ', $candidates)
        );
    }

    /** @return array<string,mixed> */
    private function readYaml(string $file): array
    {
        try {
            $parsed = str_ends_with($file, '.json')
                ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
                // PARSE_DATETIME, because without it Symfony turns `2026-01-01` into a Unix
                // timestamp integer — which then reaches a date column, and silently changes the
                // manifest checksum depending on the parser's mood. Normalised to strings below.
                : Yaml::parseFile($file, Yaml::PARSE_DATETIME);
        } catch (ParseException|\JsonException $e) {
            throw new RuntimeException("Regulatory pack file {$file} is not valid: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($parsed)) {
            throw new RuntimeException("Regulatory pack file {$file} did not parse to a map.");
        }

        return $this->normaliseDates($parsed);
    }

    /**
     * Dates become strings before anything else touches them: the manifest is stored as JSON and
     * hashed for the version checksum, and neither is stable if a date is sometimes a DateTime,
     * sometimes an integer timestamp and sometimes a string.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function normaliseDates(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                // Midnight UTC means the author wrote a plain date; keep it that way.
                $data[$key] = $value->format('H:i:s') === '00:00:00'
                    ? $value->format('Y-m-d')
                    : $value->format(\DateTimeInterface::ATOM);
            } elseif (is_array($value)) {
                $data[$key] = $this->normaliseDates($value);
            }
        }

        return $data;
    }
}
