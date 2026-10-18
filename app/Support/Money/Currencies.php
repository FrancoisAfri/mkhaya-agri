<?php

namespace App\Support\Money;

/**
 * The ISO-4217 active currency list, in one place.
 *
 * Every module that needed a currency picker until now hardcoded its own short array — Contract
 * Management had eight, Budget four, Tender six, Procurement four — so the same field offered
 * different options depending on which screen you were on. This is the shared list those should
 * converge on.
 *
 * Codes only, with English names: symbols are deliberately absent because several currencies share
 * one ("$", "£") and a wrong symbol on a document is worse than none. Amounts are formatted by the
 * client's Intl formatter from the code.
 */
class Currencies
{
    /**
     * Shown first in pickers — the currencies this platform's tenants actually trade in. The rest
     * follow alphabetically, so a South African user finds ZAR without scrolling but nothing is
     * missing for anyone else.
     */
    public const PRIORITY = ['ZAR', 'USD', 'EUR', 'GBP', 'BWP', 'NAD', 'LSL', 'SZL', 'MZN', 'ZWG', 'KES', 'NGN', 'GHS', 'TZS', 'UGX', 'AOA', 'ZMW', 'MWK', 'AUD', 'CAD', 'CNY', 'INR', 'AED', 'JPY', 'CHF'];

    /** @var array<string,string> ISO-4217 code => English name */
    private const NAMES = [
        'AED' => 'UAE Dirham', 'AFN' => 'Afghan Afghani', 'ALL' => 'Albanian Lek',
        'AMD' => 'Armenian Dram', 'ANG' => 'Netherlands Antillean Guilder', 'AOA' => 'Angolan Kwanza',
        'ARS' => 'Argentine Peso', 'AUD' => 'Australian Dollar', 'AWG' => 'Aruban Florin',
        'AZN' => 'Azerbaijani Manat', 'BAM' => 'Bosnia-Herzegovina Convertible Mark',
        'BBD' => 'Barbadian Dollar', 'BDT' => 'Bangladeshi Taka', 'BGN' => 'Bulgarian Lev',
        'BHD' => 'Bahraini Dinar', 'BIF' => 'Burundian Franc', 'BMD' => 'Bermudian Dollar',
        'BND' => 'Brunei Dollar', 'BOB' => 'Bolivian Boliviano', 'BRL' => 'Brazilian Real',
        'BSD' => 'Bahamian Dollar', 'BTN' => 'Bhutanese Ngultrum', 'BWP' => 'Botswana Pula',
        'BYN' => 'Belarusian Ruble', 'BZD' => 'Belize Dollar', 'CAD' => 'Canadian Dollar',
        'CDF' => 'Congolese Franc', 'CHF' => 'Swiss Franc', 'CLP' => 'Chilean Peso',
        'CNY' => 'Chinese Yuan', 'COP' => 'Colombian Peso', 'CRC' => 'Costa Rican Colon',
        'CUP' => 'Cuban Peso', 'CVE' => 'Cape Verdean Escudo', 'CZK' => 'Czech Koruna',
        'DJF' => 'Djiboutian Franc', 'DKK' => 'Danish Krone', 'DOP' => 'Dominican Peso',
        'DZD' => 'Algerian Dinar', 'EGP' => 'Egyptian Pound', 'ERN' => 'Eritrean Nakfa',
        'ETB' => 'Ethiopian Birr', 'EUR' => 'Euro', 'FJD' => 'Fijian Dollar',
        'FKP' => 'Falkland Islands Pound', 'GBP' => 'Pound Sterling', 'GEL' => 'Georgian Lari',
        'GHS' => 'Ghanaian Cedi', 'GIP' => 'Gibraltar Pound', 'GMD' => 'Gambian Dalasi',
        'GNF' => 'Guinean Franc', 'GTQ' => 'Guatemalan Quetzal', 'GYD' => 'Guyanaese Dollar',
        'HKD' => 'Hong Kong Dollar', 'HNL' => 'Honduran Lempira', 'HTG' => 'Haitian Gourde',
        'HUF' => 'Hungarian Forint', 'IDR' => 'Indonesian Rupiah', 'ILS' => 'Israeli New Shekel',
        'INR' => 'Indian Rupee', 'IQD' => 'Iraqi Dinar', 'IRR' => 'Iranian Rial',
        'ISK' => 'Icelandic Krona', 'JMD' => 'Jamaican Dollar', 'JOD' => 'Jordanian Dinar',
        'JPY' => 'Japanese Yen', 'KES' => 'Kenyan Shilling', 'KGS' => 'Kyrgystani Som',
        'KHR' => 'Cambodian Riel', 'KMF' => 'Comorian Franc', 'KPW' => 'North Korean Won',
        'KRW' => 'South Korean Won', 'KWD' => 'Kuwaiti Dinar', 'KYD' => 'Cayman Islands Dollar',
        'KZT' => 'Kazakhstani Tenge', 'LAK' => 'Laotian Kip', 'LBP' => 'Lebanese Pound',
        'LKR' => 'Sri Lankan Rupee', 'LRD' => 'Liberian Dollar', 'LSL' => 'Lesotho Loti',
        'LYD' => 'Libyan Dinar', 'MAD' => 'Moroccan Dirham', 'MDL' => 'Moldovan Leu',
        'MGA' => 'Malagasy Ariary', 'MKD' => 'Macedonian Denar', 'MMK' => 'Myanmar Kyat',
        'MNT' => 'Mongolian Tugrik', 'MOP' => 'Macanese Pataca', 'MRU' => 'Mauritanian Ouguiya',
        'MUR' => 'Mauritian Rupee', 'MVR' => 'Maldivian Rufiyaa', 'MWK' => 'Malawian Kwacha',
        'MXN' => 'Mexican Peso', 'MYR' => 'Malaysian Ringgit', 'MZN' => 'Mozambican Metical',
        'NAD' => 'Namibian Dollar', 'NGN' => 'Nigerian Naira', 'NIO' => 'Nicaraguan Cordoba',
        'NOK' => 'Norwegian Krone', 'NPR' => 'Nepalese Rupee', 'NZD' => 'New Zealand Dollar',
        'OMR' => 'Omani Rial', 'PAB' => 'Panamanian Balboa', 'PEN' => 'Peruvian Sol',
        'PGK' => 'Papua New Guinean Kina', 'PHP' => 'Philippine Peso', 'PKR' => 'Pakistani Rupee',
        'PLN' => 'Polish Zloty', 'PYG' => 'Paraguayan Guarani', 'QAR' => 'Qatari Riyal',
        'RON' => 'Romanian Leu', 'RSD' => 'Serbian Dinar', 'RUB' => 'Russian Ruble',
        'RWF' => 'Rwandan Franc', 'SAR' => 'Saudi Riyal', 'SBD' => 'Solomon Islands Dollar',
        'SCR' => 'Seychellois Rupee', 'SDG' => 'Sudanese Pound', 'SEK' => 'Swedish Krona',
        'SGD' => 'Singapore Dollar', 'SHP' => 'Saint Helena Pound', 'SLE' => 'Sierra Leonean Leone',
        'SOS' => 'Somali Shilling', 'SRD' => 'Surinamese Dollar', 'SSP' => 'South Sudanese Pound',
        'STN' => 'Sao Tome and Principe Dobra', 'SVC' => 'Salvadoran Colon', 'SYP' => 'Syrian Pound',
        'SZL' => 'Swazi Lilangeni', 'THB' => 'Thai Baht', 'TJS' => 'Tajikistani Somoni',
        'TMT' => 'Turkmenistani Manat', 'TND' => 'Tunisian Dinar', 'TOP' => 'Tongan Paanga',
        'TRY' => 'Turkish Lira', 'TTD' => 'Trinidad and Tobago Dollar', 'TWD' => 'New Taiwan Dollar',
        'TZS' => 'Tanzanian Shilling', 'UAH' => 'Ukrainian Hryvnia', 'UGX' => 'Ugandan Shilling',
        'USD' => 'US Dollar', 'UYU' => 'Uruguayan Peso', 'UZS' => 'Uzbekistani Som',
        'VES' => 'Venezuelan Bolivar', 'VND' => 'Vietnamese Dong', 'VUV' => 'Vanuatu Vatu',
        'WST' => 'Samoan Tala', 'XAF' => 'Central African CFA Franc', 'XCD' => 'East Caribbean Dollar',
        'XOF' => 'West African CFA Franc', 'XPF' => 'CFP Franc', 'YER' => 'Yemeni Rial',
        'ZAR' => 'South African Rand', 'ZMW' => 'Zambian Kwacha', 'ZWG' => 'Zimbabwe Gold',
    ];

    /** @return array<string,string> every currency, code => name */
    public static function all(): array
    {
        return self::NAMES;
    }

    /** @return list<string> every code */
    public static function codes(): array
    {
        return array_keys(self::NAMES);
    }

    public static function isValid(string $code): bool
    {
        return isset(self::NAMES[strtoupper($code)]);
    }

    public static function name(string $code): ?string
    {
        return self::NAMES[strtoupper($code)] ?? null;
    }

    /**
     * Picker options: priority currencies first, then everything else alphabetically by name.
     *
     * @return list<array{code:string,name:string,priority:bool}>
     */
    public static function options(): array
    {
        $priority = [];
        $rest = [];

        foreach (self::NAMES as $code => $name) {
            $row = ['code' => $code, 'name' => $name, 'priority' => in_array($code, self::PRIORITY, true)];
            $row['priority'] ? $priority[$code] = $row : $rest[] = $row;
        }

        // Keep PRIORITY's own order — it is deliberate (home currency, then majors, then region).
        $ordered = [];
        foreach (self::PRIORITY as $code) {
            if (isset($priority[$code])) {
                $ordered[] = $priority[$code];
            }
        }

        usort($rest, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return array_merge($ordered, $rest);
    }
}
