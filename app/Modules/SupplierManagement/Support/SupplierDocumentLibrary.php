<?php

namespace App\Modules\SupplierManagement\Support;

/**
 * The compliance documents a South African supplier is normally asked for.
 *
 * None of these can be verified through an API. CIPC data is reachable through a paid aggregator and
 * SARS tax status through a PIN, but the Department of Employment and Labour issues a Letter of Good
 * Standing through a portal and publishes no interface at all — so for the statutory set,
 * verification *is* the document and its expiry date, or it is nothing.
 *
 * That makes the two unglamorous fields here the important ones. `default_validity_months` lets the
 * upload form offer the expiry date instead of leaving somebody to work it out from a certificate,
 * and `guidance` says where to obtain the thing — which is the whole of the help we can give when
 * there is nothing to call.
 *
 * `compulsory` is set only where a buyer genuinely cannot place work without it. Marking everything
 * compulsory produces a directory where every supplier is permanently non-compliant, and a warning
 * that is always on is a warning nobody reads.
 */
class SupplierDocumentLibrary
{
    /**
     * @return list<array{code: string, name: string, authority: string, months: ?int, compulsory: bool, guidance: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => 'cipc_registration',
                'name' => 'Company registration certificate (CIPC)',
                'authority' => 'Companies and Intellectual Property Commission',
                // A registration certificate does not expire; the company either exists or it does not.
                'months' => null,
                'compulsory' => true,
                'guidance' => 'The CoR 14.3 registration certificate, or the disclosure certificate from '
                    .'CIPC eServices. A sole proprietor has none — record that instead of leaving this empty.',
            ],
            [
                'code' => 'tax_clearance',
                'name' => 'Tax Compliance Status (SARS)',
                'authority' => 'South African Revenue Service',
                'months' => 12,
                'compulsory' => true,
                'guidance' => 'Issued on SARS eFiling as a Tax Compliance Status PIN. The PIN lets you check '
                    .'the status yourself at any time, so record it on the supplier as well as filing the letter.',
            ],
            [
                'code' => 'coida_good_standing',
                'name' => 'Letter of Good Standing (COIDA)',
                'authority' => 'Compensation Fund — Department of Employment and Labour',
                // Issued for a year at a time, and the single most commonly lapsed document on site.
                'months' => 12,
                'compulsory' => true,
                'guidance' => 'Obtained from the Compensation Fund through CompEasy, once the employer is '
                    .'registered under the Compensation for Occupational Injuries and Diseases Act. Required '
                    .'before a contractor may work on most sites, and it lapses annually.',
            ],
            [
                'code' => 'uif_registration',
                'name' => 'UIF registration confirmation',
                'authority' => 'Unemployment Insurance Fund — Department of Employment and Labour',
                'months' => null,
                'compulsory' => false,
                'guidance' => 'Proof of registration from uFiling. Needed by any supplier with employees; '
                    .'a supplier with none can record that in place of the document.',
            ],
            [
                'code' => 'bbbee_certificate',
                'name' => 'B-BBEE certificate or affidavit',
                'authority' => 'SANAS-accredited verification agency, or a sworn affidavit',
                'months' => 12,
                'compulsory' => false,
                'guidance' => 'An exempted micro enterprise may submit a sworn affidavit instead of a verified '
                    .'certificate. Both expire after a year.',
            ],
            [
                'code' => 'vat_certificate',
                'name' => 'VAT registration certificate',
                'authority' => 'South African Revenue Service',
                'months' => null,
                'compulsory' => false,
                'guidance' => 'The VAT103 notice of registration. Only applies above the registration threshold — '
                    .'a supplier that is not registered should not be chased for one.',
            ],
            [
                'code' => 'bank_confirmation',
                'name' => 'Bank confirmation letter',
                'authority' => "The supplier's bank",
                // Deliberately short. This is the document that defends against a changed account
                // number, and a two-year-old letter proves nothing about where money goes today.
                'months' => 3,
                'compulsory' => true,
                'guidance' => 'Stamped by the bank and dated. This is what a changed account number is checked '
                    .'against, so an old one is worth little — ask for a current letter whenever banking details change.',
            ],
            [
                'code' => 'public_liability',
                'name' => 'Public liability insurance',
                'authority' => "The supplier's insurer",
                'months' => 12,
                'compulsory' => false,
                'guidance' => 'The schedule showing the insured amount and the period of cover. Check the amount '
                    .'against the work, not only that a policy exists.',
            ],
            [
                'code' => 'ohs_compliance',
                'name' => 'Occupational health and safety file',
                'authority' => 'The supplier, under the Occupational Health and Safety Act',
                'months' => 12,
                'compulsory' => false,
                'guidance' => 'Required of any contractor working on your premises. Usually reviewed annually '
                    .'and whenever the scope of work changes.',
            ],
            [
                'code' => 'director_identity',
                'name' => 'Director identity documents',
                'authority' => 'Department of Home Affairs',
                'months' => null,
                'compulsory' => false,
                'guidance' => 'Identity documents or passports for the directors named on the registration '
                    .'certificate, so the people behind the company are on record.',
            ],
        ];
    }

    /**
     * Older installations named two of these differently, and a tenant may have renamed any of them.
     *
     * Matched on a normalised name so the first install adopts the existing row and stamps its code,
     * rather than leaving a tenant with "Tax Clearance Certificate" and "Tax Compliance Status (SARS)"
     * side by side and no way to tell which one to upload against.
     *
     * @return array<string, string> normalised existing name => library code
     */
    public static function legacyNames(): array
    {
        return [
            'tax clearance certificate' => 'tax_clearance',
            'bbbee certificate' => 'bbbee_certificate',
            'b-bbee certificate' => 'bbbee_certificate',
            'company registration certificate' => 'cipc_registration',
            'letter of good standing' => 'coida_good_standing',
            'bank confirmation letter' => 'bank_confirmation',
        ];
    }

    public static function normalise(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
    }
}
