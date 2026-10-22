<?php

namespace App\Modules\Agri\Support;

/**
 * A STARTING POINT for the MRL register, not a reference: indicative maximum residue limits (mg/kg)
 * for actives commonly used on South African export crops, and actives commonly banned or
 * restricted in the main markets. Every row is installed as editable and marked "starter"; verify
 * each against the official databases (EU Pesticides Database, UK MRL database, the South African
 * Act 36 MRL list, China GB 2763, the US eCFR 40 CFR 180, Codex Alimentarius) before relying on it,
 * or load the official lists with the CSV import.
 */
final class MrlStarterSet
{
    public const SOURCE = 'Mkhaya starter set — indicative only; verify against the official database';

    public const EFFECTIVE_FROM = '2026-01-01';

    /** The column order of LIMITS' values. */
    public const MARKETS = ['EU', 'UK', 'ZA', 'CN', 'US', 'CODEX'];

    /**
     * crop code (the farm catalogue's) => active => [EU, UK, ZA, CN, US, CODEX] in mg/kg (null: no value given).
     *
     * @var array<string, array<string, list<float|null>>>
     */
    public const LIMITS = [
        'CITRUS' => [
            'Imazalil' => [4, 4, 5, 5, 10, 5],
            'Thiabendazole' => [7, 7, 10, 10, 10, 7],
            'Pyrimethanil' => [8, 8, 10, 7, 10, 7],
            'Fludioxonil' => [10, 10, 10, 10, 10, 10],
            '2,4-D' => [1, 1, 2, 1, 3, 1],
            'Chlorpyrifos' => [0.01, 0.01, 0.3, 1, 1, 1],
            'Abamectin' => [0.015, 0.015, 0.01, 0.02, 0.02, 0.02],
            'Spirotetramat' => [1, 1, 0.5, 1, 0.6, 0.5],
        ],
        'GRAPE' => [
            'Fenhexamid' => [15, 15, 5, 15, 4, 15],
            'Pyrimethanil' => [5, 5, 5, 4, 5, 4],
            'Tebuconazole' => [1, 1, 2, 2, 5, 6],
            'Acetamiprid' => [0.5, 0.5, 0.5, 0.5, 0.35, 0.5],
            'Chlorpyrifos' => [0.01, 0.01, 0.5, 0.5, 0.01, 1],
            'Iprodione' => [0.01, 0.01, 10, 10, 60, 10],
        ],
        'APPLE' => [
            'Captan' => [10, 10, 15, 15, 25, 15],
            'Acetamiprid' => [0.4, 0.4, 0.8, 0.8, 1, 0.8],
            'Pyrimethanil' => [15, 15, 10, 7, 14, 15],
            'Fludioxonil' => [5, 5, 5, 5, 5, 5],
            'Diphenylamine' => [0.05, 0.05, 10, 5, 10, 10],
            'Chlorpyrifos' => [0.01, 0.01, 0.5, 1, 0.01, 1],
        ],
        'AVOCADO' => [
            'Prochloraz' => [7, 7, 5, 7, null, 7],
            'Abamectin' => [0.01, 0.01, 0.01, 0.02, 0.02, 0.02],
            'Thiabendazole' => [15, 15, 10, 15, 10, 15],
            'Spinetoram' => [0.3, 0.3, 0.2, 0.3, 0.3, 0.3],
        ],
        'MACADAMIA' => [
            'Chlorpyrifos' => [0.01, 0.01, 0.05, 0.05, 0.2, 0.05],
            'Glyphosate' => [0.1, 0.1, 0.1, 1, 1, 1],
            'Tebuconazole' => [0.05, 0.05, 0.05, 0.05, 0.05, 0.05],
            'Bifenthrin' => [0.05, 0.05, 0.05, 0.05, 0.05, 0.05],
        ],
    ];

    /** market => active => banned|restricted (indicative; verify). */
    public const RESTRICTIONS = [
        'EU' => ['Chlorpyrifos' => 'banned', 'Chlorpyrifos-methyl' => 'banned', 'Carbendazim' => 'banned', 'Iprodione' => 'banned', 'Mancozeb' => 'banned',
            'Dimethoate' => 'banned', 'Atrazine' => 'banned', 'Imidacloprid' => 'restricted', 'Thiacloprid' => 'restricted'],
        'UK' => ['Chlorpyrifos' => 'banned', 'Chlorpyrifos-methyl' => 'banned', 'Carbendazim' => 'banned', 'Iprodione' => 'banned', 'Mancozeb' => 'banned',
            'Dimethoate' => 'banned', 'Atrazine' => 'banned', 'Imidacloprid' => 'restricted', 'Thiacloprid' => 'restricted'],
    ];

    /** The markets every organisation starts with: code => [name, countries, default MRL, result required, default target]. */
    public const DEFAULT_MARKETS = [
        'EU' => ['European Union', ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'], 0.01, true, false],
        'UK' => ['United Kingdom', ['GB'], 0.01, true, false],
        'ZA' => ['South Africa (domestic)', ['ZA', 'NA', 'BW', 'LS', 'SZ'], null, false, true],
        'CN' => ['China', ['CN', 'HK', 'MO'], null, false, false],
        'US' => ['United States', ['US'], null, false, false],
        'CODEX' => ['Codex Alimentarius (other markets)', [], null, false, false],
    ];

    /** Customs commodity codes (HS) a consignment line starts with, per catalogue crop code. Editable on the line. */
    public const HS_CODES = ['CITRUS' => '0805.50', 'GRAPE' => '0806.10', 'APPLE' => '0808.10', 'AVOCADO' => '0804.40', 'MACADAMIA' => '0802.62'];
}
