<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * The thirteen annexures, as data.
 *
 * Declared rather than coded, in the same spirit as RoleCatalog and DerivationRules: which
 * annexures exist, what each is called, which of the five line calculations it uses and whether it
 * carries a surcharge are facts about the client's workbook, not behaviour. Somebody adding an
 * annexure should be editing a list.
 *
 * ── The numbering has gaps, and they are kept ───────────────────────────────
 *
 * The workbook runs 1–7, then 11, 12, 13. There is no 8, 9 or 10, just as Form 1 has no stage 3.7.
 * Closing the gaps would renumber a document the client has been issuing for years and reconciling
 * against; "Annexure 12" has to stay Annexure 12.
 *
 * ── Line types ──────────────────────────────────────────────────────────────
 *
 * Every annexure resolves to one of the five calculations in AnnexureLine. Naming them here is
 * what stops the 10% surcharge being written four slightly different ways across 4, 5, 12 and 13.
 */
final class AnnexureCatalogue
{
    public const DOCUMENTATION = 'documentation';

    public const TRAVELLING = 'travelling';

    public const TRAVELLING_TIME = 'travelling_time';

    public const SURCHARGED = 'surcharged';

    public const TIME_BASIS = 'time_basis';

    /**
     * number => [title, line_type, surcharge, summary_description]
     *
     * `summary_description` is the wording the Summary sheet uses for that annexure's row, which is
     * not always the annexure's own title — the Summary says "Travelling and subsistence" where the
     * annexure is headed "CLAIMSHEET: TRAVELLING AND SUBSISTENCE".
     *
     * @var array<int, array{title: string, line_type: string, surcharge: ?string, summary_description: string}>
     */
    private const ENTRIES = [
        1 => [
            'title'               => 'Documentation',
            'line_type'           => self::DOCUMENTATION,
            'surcharge'           => null,
            'summary_description' => 'Documentation',
        ],
        2 => [
            'title'               => 'Travelling and Subsistence',
            'line_type'           => self::TRAVELLING,
            'surcharge'           => null,
            'summary_description' => 'Travelling and subsistence',
        ],
        3 => [
            'title'               => 'Travelling Time',
            'line_type'           => self::TRAVELLING_TIME,
            'surcharge'           => null,
            'summary_description' => 'Travelling time',
        ],
        4 => [
            'title'               => 'Geotechnical Investigations',
            'line_type'           => self::SURCHARGED,
            'surcharge'           => AnnexureLine::DEFAULT_SURCHARGE,
            'summary_description' => 'Geotechnical engineer',
        ],
        5 => [
            'title'               => 'Topographical Survey',
            'line_type'           => self::SURCHARGED,
            'surcharge'           => AnnexureLine::DEFAULT_SURCHARGE,
            'summary_description' => 'Topographical survey',
        ],
        6 => [
            'title'               => 'Occupational Health & Safety',
            'line_type'           => self::SURCHARGED,
            'surcharge'           => AnnexureLine::DEFAULT_SURCHARGE,
            'summary_description' => 'OHS consultant',
        ],
        7 => [
            'title'               => 'Social Facilitation',
            'line_type'           => self::SURCHARGED,
            'surcharge'           => AnnexureLine::DEFAULT_SURCHARGE,
            'summary_description' => 'Social Facilitation',
        ],
        // 8, 9 and 10 are absent from the client's workbook. See the class docblock.
        11 => [
            'title'               => 'Time Basis for Rework',
            'line_type'           => self::TIME_BASIS,
            'surcharge'           => null,
            'summary_description' => 'Time basis',
        ],
        12 => [
            'title'               => 'Advertising',
            'line_type'           => self::SURCHARGED,
            'surcharge'           => AnnexureLine::DEFAULT_SURCHARGE,
            'summary_description' => 'Advertising',
        ],
        13 => [
            'title'               => 'Student Training',
            'line_type'           => self::SURCHARGED,
            'surcharge'           => AnnexureLine::DEFAULT_SURCHARGE,
            'summary_description' => 'Student Training',
        ],
    ];

    /**
     * Disbursement categories the Summary lists that no annexure feeds.
     *
     * The client's Summary carries thirteen categories; ten of them have an annexure behind them
     * and three do not — they are entered directly on Form 2. Listing them keeps the Summary a
     * complete reproduction rather than only the parts that happen to have a schedule.
     */
    public const DIRECT_CATEGORIES = [
        'Monitoring and supervision',
        'Environmental consultant',
        'Geohydrologist',
        'Town planners',
    ];

    /**
     * The thirteen disbursement categories, in the order the client's Summary lists them.
     *
     * Order matters as much as membership: this is the sheet their finance people read down, and a
     * category appearing in a different place reads as a different document. Nine are fed by an
     * annexure and four are typed on Form 2, but the Summary interleaves them — so the sequence
     * cannot be derived from the annexure numbering and is stated here.
     */
    public const SUMMARY_CATEGORIES = [
        'Documentation',
        'Travelling and subsistence',
        'Travelling time',
        'Monitoring and supervision',
        'Environmental consultant',
        'OHS consultant',
        'Geotechnical engineer',
        'Topographical survey',
        'Geohydrologist',
        'Town planners',
        'Social Facilitation',
        'Advertising',
        'Student Training',
    ];

    /** @return list<string> */
    public static function summaryCategories(): array
    {
        return self::SUMMARY_CATEGORIES;
    }

    /** Where a category sits on the Summary, for ordering the rows a person adds in any order. */
    public static function summarySort(string $description): int
    {
        $index = array_search($description, self::SUMMARY_CATEGORIES, true);

        // Anything the client later adds sorts after the thirteen rather than being refused.
        return $index === false ? 99 : $index;
    }

    private function __construct()
    {
    }

    /** @return array<int, array{title:string, line_type:string, surcharge:?string, summary_description:string}> */
    public static function all(): array
    {
        return self::ENTRIES;
    }

    /** @return list<int> */
    public static function numbers(): array
    {
        return array_keys(self::ENTRIES);
    }

    public static function exists(int $number): bool
    {
        return array_key_exists($number, self::ENTRIES);
    }

    /** @return array{title:string, line_type:string, surcharge:?string, summary_description:string} */
    public static function get(int $number): array
    {
        if (! self::exists($number)) {
            throw new \InvalidArgumentException("There is no Annexure {$number} in this account.");
        }

        return self::ENTRIES[$number];
    }

    public static function title(int $number): string
    {
        return self::get($number)['title'];
    }

    public static function lineType(int $number): string
    {
        return self::get($number)['line_type'];
    }

    public static function surcharge(int $number): ?string
    {
        return self::get($number)['surcharge'];
    }

    public static function summaryDescription(int $number): string
    {
        return self::get($number)['summary_description'];
    }

    /**
     * Compute one line, dispatching on the annexure's type.
     *
     * The single place a line's calculation is chosen, so a new annexure of an existing shape needs
     * no code at all — only an entry above.
     *
     * @param  array<string, string|float|int|null|bool>  $item
     */
    public static function calculate(int $number, array $item): string
    {
        $entry = self::get($number);

        return match ($entry['line_type']) {
            self::DOCUMENTATION => AnnexureLine::documentation(
                $item['quantity'] ?? 0,
                $item['tariff'] ?? 0,
            ),
            self::TRAVELLING => AnnexureLine::travelling(
                $item['kilometres'] ?? 0,
                $item['tariff'] ?? 0,
                $item['less_kilometres'] ?? 0,
            ),
            self::TRAVELLING_TIME => AnnexureLine::travellingTime(
                $item['kilometres'] ?? 0,
                $item['less_hours'] ?? 0,
                $item['tariff'] ?? 0,
            ),
            self::SURCHARGED => AnnexureLine::surcharged(
                $item['appointment_amount'] ?? 0,
                $entry['surcharge'] ?? AnnexureLine::DEFAULT_SURCHARGE,
                (bool) ($item['claimed_this_period'] ?? true),
            ),
            self::TIME_BASIS => AnnexureLine::timeBasis(
                $item['tariff'] ?? 0,
                $item['hours'] ?? 0,
            ),
            default => '0',
        };
    }
}
