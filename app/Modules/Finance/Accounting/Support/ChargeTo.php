<?php

namespace App\Modules\Finance\Accounting\Support;

/**
 * Where a sales document's income is charged: the ledger dimensions it carries.
 *
 * Accounting knows nothing of farms, projects or sites; a module that owns such a thing registers it
 * here — the choices a person picks from ("Farm: Boplaas") and how a choice becomes dimensions. A
 * quote or order raised in Accounting then lands in that module's figures exactly as one raised from
 * the module would, and Accounting stays free of any one module.
 */
class ChargeTo
{
    /** @var array<string, array{options: callable(): array, resolve: callable(string): ?array}> */
    private static array $sources = [];

    /**
     * @param  callable(): list<array{value: string, title: string}>  $options  values are "<prefix>:<id>"
     * @param  callable(string): ?array  $resolve  the id part → dimensions, or null when not found or not allowed
     */
    public static function register(string $prefix, callable $options, callable $resolve): void
    {
        self::$sources[$prefix] = ['options' => $options, 'resolve' => $resolve];
    }

    /** @return list<array{value: string, title: string}> */
    public static function options(): array
    {
        return collect(self::$sources)->flatMap(fn ($s) => ($s['options'])())->values()->all();
    }

    /** "<prefix>:<id>" → dimensions; null for no choice; an unknown or refused choice throws. */
    public static function dimensions(?string $choice): ?array
    {
        if ($choice === null || $choice === '') {
            return null;
        }
        [$prefix, $id] = array_pad(explode(':', $choice, 2), 2, null);
        $dims = isset(self::$sources[$prefix]) && $id !== null ? (self::$sources[$prefix]['resolve'])($id) : null;
        if (! $dims) {
            throw new \InvalidArgumentException('Choose where to charge this from the list.');
        }

        return $dims + ['charge_to' => $choice];
    }

    /** The choice a document's dimensions came from, for showing it again. */
    public static function choiceOf(?array $dimensions): ?string
    {
        return $dimensions['charge_to'] ?? null;
    }
}
