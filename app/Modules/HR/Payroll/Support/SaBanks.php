<?php

namespace App\Modules\HR\Payroll\Support;

/**
 * South African banks and the salary-file format their bulk-payment importer
 * accepts. The underlying layout is the BankservAfrica/ACB EFT standard; the
 * employer uploads the generated file into their own internet banking. Exact
 * field packs vary per bank — confirmed identifiers (the bank-issued user code)
 * are captured per company in setup.
 */
class SaBanks
{
    /** code => [name, default branch (universal), file format profile] */
    public const BANKS = [
        'standard_bank' => ['name' => 'Standard Bank',      'universal_branch' => '051001', 'format' => 'acb'],
        'absa'          => ['name' => 'Absa',               'universal_branch' => '632005', 'format' => 'acb'],
        'fnb'           => ['name' => 'FNB',                'universal_branch' => '250655', 'format' => 'acb'],
        'nedbank'       => ['name' => 'Nedbank',            'universal_branch' => '198765', 'format' => 'acb'],
        'capitec'       => ['name' => 'Capitec',            'universal_branch' => '470010', 'format' => 'acb'],
        'investec'      => ['name' => 'Investec',           'universal_branch' => '580105', 'format' => 'acb'],
        'tymebank'      => ['name' => 'TymeBank',           'universal_branch' => '678910', 'format' => 'acb'],
        'generic_csv'   => ['name' => 'Other / Generic CSV', 'universal_branch' => '000000', 'format' => 'csv'],
    ];

    public static function codes(): array
    {
        return array_keys(self::BANKS);
    }

    public static function exists(string $code): bool
    {
        return isset(self::BANKS[$code]);
    }

    public static function name(string $code): string
    {
        return self::BANKS[$code]['name'] ?? $code;
    }

    public static function format(string $code): string
    {
        return self::BANKS[$code]['format'] ?? 'acb';
    }

    /** Options for a UI select. */
    public static function options(): array
    {
        return array_map(fn ($code) => ['value' => $code, 'title' => self::BANKS[$code]['name']], self::codes());
    }
}
