<?php

namespace App\Modules\Operations\Sheq\Regulatory;

use InvalidArgumentException;

/**
 * An installed Regulatory Pack version, in memory (§3).
 *
 * The manifest is the whole compiled bundle — classifications, obligations, metric conventions,
 * forms, templates, legal register seed, retention policy — stored as one immutable blob so a
 * version is reproducible exactly as shipped, independent of the files that happen to be on disk
 * today. That matters for SHEQ-REG-2: an incident from last year must still be explainable by the
 * rules that judged it, even after the pack directory has been updated three times.
 */
final class PackManifest
{
    /** @param array<string,mixed> $manifest */
    public function __construct(
        public readonly string $packId,
        public readonly string $version,
        public readonly array $manifest,
    ) {}

    /** @param array<string,mixed> $manifest */
    public static function fromArray(array $manifest): self
    {
        foreach (['pack_id', 'version'] as $required) {
            if (empty($manifest[$required])) {
                throw new InvalidArgumentException("Regulatory pack manifest is missing `{$required}`.");
            }
        }

        return new self((string) $manifest['pack_id'], (string) $manifest['version'], $manifest);
    }

    public function jurisdiction(): string
    {
        return (string) ($this->manifest['jurisdiction'] ?? 'UNKNOWN');
    }

    /**
     * The site types this pack is written for.
     *
     * `za-mhsa` covers mines, shafts and opencast workings; `za-ohsa` covers offices, plants,
     * depots and projects. A group running both needs each site governed by the right one, which
     * is what per-site activation is for — this is how the screen knows which sites a pack
     * belongs on.
     *
     * Empty means the pack does not say, and nothing should be inferred from that: an unstated
     * list is not a claim that it applies everywhere.
     *
     * @return list<string>
     */
    public function appliesToSiteTypes(): array
    {
        return array_values(array_map(
            static fn ($type) => strtoupper((string) $type),
            (array) ($this->manifest['applies_to_site_types'] ?? []),
        ));
    }

    public function label(): string
    {
        return (string) ($this->manifest['name'] ?? $this->packId);
    }

    /**
     * Classification rule sets, in declared order. Order is semantic: rules are evaluated top-down
     * and the first match wins, which is how a fatality lands on OHSA_S24(a) rather than also
     * picking up the generic "recordable" rule beneath it.
     *
     * @return list<array<string,mixed>>
     */
    public function classificationRules(): array
    {
        $rules = [];
        foreach ($this->manifest['classifications'] ?? [] as $set) {
            foreach ($set['rules'] ?? [] as $rule) {
                $rules[] = $rule + ['_set' => $set['id'] ?? null];
            }
        }

        return $rules;
    }

    /** @return array<string,mixed>|null */
    public function obligation(string $id): ?array
    {
        return $this->manifest['obligations'][$id] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public function obligations(): array
    {
        return $this->manifest['obligations'] ?? [];
    }

    /** @return array<string,mixed> */
    public function metrics(): array
    {
        return $this->manifest['metrics'] ?? [];
    }

    /** @return array<string,mixed> */
    public function retention(): array
    {
        return $this->manifest['retention'] ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function legalRegister(): array
    {
        return $this->manifest['legal_register'] ?? [];
    }

    /**
     * SHEQ-INC-7: investigation rigour follows *potential* severity, not actual — a near-miss that
     * could have killed someone is investigated like the fatality it nearly was. The mapping is the
     * pack's, not the code's.
     *
     * @return array<string,mixed>
     */
    public function investigationRules(): array
    {
        return $this->manifest['investigation'] ?? [];
    }

    /**
     * Canonical checksum over the manifest. Keys are sorted recursively so an equivalent manifest
     * written in a different order hashes identically — otherwise re-installing an unchanged pack
     * would look like tampering.
     */
    public function checksum(): string
    {
        return hash('sha256', self::canonicalJson($this->manifest));
    }

    public static function canonicalJson(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
