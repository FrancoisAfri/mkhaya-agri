<?php

namespace App\Modules\Finance\Sars\Services;

use App\Modules\Finance\Sars\Models\SarsTaxConfig;

/**
 * SARS validation engine. Format-checks the fields SARS requires before a return can be
 * exported — registration numbers, employee tax/ID numbers, mandatory fields, duplicates.
 * Everything returns a uniform result { validated: int, errors: [{entity,id,field,message}] }
 * so the UI can render the "1,284 validated — 3 errors" report and block export until clean.
 */
class SarsValidator
{
    // SARS reference formats (10 digits with a leading class digit; SDL/UIF have a prefix).
    private const RE_VAT        = '/^4\d{9}$/';         // VAT vendor: 10 digits, starts 4
    private const RE_PAYE       = '/^7\d{9}$/';         // PAYE (EMP): 10 digits, starts 7
    private const RE_SDL        = '/^L\d{9}$/i';        // SDL: L + 9 digits
    private const RE_UIF        = '/^(U\d{9}|7\d{9})$/i'; // UIF: U + 9, or the PAYE number
    private const RE_INCOME_TAX = '/^\d{10}$/';         // Income tax ref: 10 digits
    private const RE_TAX_REF    = '/^\d{10}$/';         // Employee income-tax number: 10 digits

    /** Validate the company's SARS registration profile. */
    public function validateConfig(SarsTaxConfig $c): array
    {
        $errors = [];
        $push = function (string $field, string $message) use (&$errors) {
            $errors[] = ['entity' => 'Company', 'id' => null, 'field' => $field, 'message' => $message];
        };

        // VAT — only required/checked when registered as a vendor.
        if ($c->vat_registered) {
            if (blank($c->vat_number)) {
                $push('VAT Number', 'Missing — required for a VAT vendor.');
            } elseif (! $this->matchesVat($c->vat_number)) {
                $push('VAT Number', 'Invalid format (must be 10 digits starting with 4).');
            }
            if (blank($c->vat_frequency)) {
                $push('VAT Frequency', 'Choose a filing frequency.');
            }
        }

        // PAYE / UIF / SDL — payroll statutory numbers.
        if (filled($c->paye_reference) && ! preg_match(self::RE_PAYE, $c->paye_reference)) {
            $push('PAYE Number', 'Invalid format (10 digits starting with 7).');
        }
        if (filled($c->uif_reference) && ! preg_match(self::RE_UIF, $c->uif_reference)) {
            $push('UIF Number', 'Invalid format (U followed by 9 digits, or the PAYE number).');
        }
        if ($c->sdl_liable && blank($c->sdl_reference)) {
            $push('SDL Number', 'Missing — required when the company is SDL-liable.');
        }
        if (filled($c->sdl_reference) && ! preg_match(self::RE_SDL, $c->sdl_reference)) {
            $push('SDL Number', 'Invalid format (L followed by 9 digits).');
        }
        if (filled($c->income_tax_number) && ! preg_match(self::RE_INCOME_TAX, $c->income_tax_number)) {
            $push('Income Tax Number', 'Invalid format (10 digits).');
        }
        if (filled($c->contact_email) && ! filter_var($c->contact_email, FILTER_VALIDATE_EMAIL)) {
            $push('Contact Email', 'Invalid email address.');
        }

        return ['validated' => 1, 'errors' => $errors];
    }

    /**
     * Validate a set of employee rows for certificate generation.
     * @param  iterable<array{id:mixed,label:string,tax_number:?string,id_number:?string,passport:?string,address:?string}>  $employees
     */
    public function validateEmployees(iterable $employees, array $opts = []): array
    {
        $requireAddress = $opts['require_address'] ?? false;
        $errors = [];
        $count = 0;
        $seenTax = [];

        foreach ($employees as $e) {
            $count++;
            $label = (string) ($e['label'] ?? $e['id'] ?? '');
            $push = function (string $field, string $message) use (&$errors, $e, $label) {
                $errors[] = ['entity' => 'Employee ' . $label, 'id' => $e['id'] ?? null, 'field' => $field, 'message' => $message];
            };

            $tax = trim((string) ($e['tax_number'] ?? ''));
            if ($tax === '') {
                $push('Tax Number', 'Missing tax number.');
            } elseif (! preg_match(self::RE_TAX_REF, $tax)) {
                $push('Tax Number', 'Invalid tax number (10 digits).');
            } else {
                if (isset($seenTax[$tax])) {
                    $push('Tax Number', 'Duplicate — same tax number as ' . $seenTax[$tax] . '.');
                }
                $seenTax[$tax] = $label;
            }

            $id = trim((string) ($e['id_number'] ?? ''));
            $passport = trim((string) ($e['passport'] ?? ''));
            if ($id === '' && $passport === '') {
                $push('ID Number', 'Missing ID or passport number.');
            } elseif ($id !== '' && ! $this->validSaId($id)) {
                $push('ID Number', 'Invalid ID number (13 digits, date and checksum).');
            }

            if ($requireAddress && trim((string) ($e['address'] ?? '')) === '') {
                $push('Address', 'Missing postal address.');
            }
        }

        return ['validated' => $count, 'errors' => $errors];
    }

    public function matchesVat(string $n): bool
    {
        return (bool) preg_match(self::RE_VAT, trim($n));
    }

    /** A South African 13-digit ID: valid birth date + Luhn check digit. */
    public function validSaId(string $id): bool
    {
        $id = trim($id);
        if (! preg_match('/^\d{13}$/', $id)) {
            return false;
        }
        // YYMMDD must be a real date.
        $mm = (int) substr($id, 2, 2);
        $dd = (int) substr($id, 4, 2);
        if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) {
            return false;
        }

        return $this->luhn($id);
    }

    /** Luhn checksum over a numeric string (the last digit is the check digit). */
    private function luhn(string $number): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $d = (int) $number[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
