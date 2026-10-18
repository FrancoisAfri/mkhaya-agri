<?php

namespace Tests\Unit\Finance;

use App\Modules\Finance\Accounting\Support\AddressText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AddressTextTest extends TestCase
{
    public static function addressesCarryingAVatNumber(): array
    {
        return [
            'the live record' => [
                'P.O. Box 6377, Secunda, 2302, VAT NO: 4370228530',
                'P.O. Box 6377, Secunda, 2302', '4370228530',
            ],
            'no label word' => [
                '12 Leslie Rd, Fourways, VAT 4370228530',
                '12 Leslie Rd, Fourways', '4370228530',
            ],
            'lowercase and no colon' => [
                '12 Leslie Rd, vat no 4370228530',
                '12 Leslie Rd', '4370228530',
            ],
            'spaced digits' => [
                'Secunda, 2302, VAT No: 4370 228 530',
                'Secunda, 2302', '4370228530',
            ],
            'on its own line' => [
                "P.O. Box 6377\nSecunda, 2302\nVAT NO: 4370228530",
                "P.O. Box 6377\nSecunda, 2302", '4370228530',
            ],
            'tax number wording' => [
                'Fourways, 2191 - Tax Number 4370228530',
                'Fourways, 2191', '4370228530',
            ],
        ];
    }

    #[DataProvider('addressesCarryingAVatNumber')]
    public function test_it_lifts_the_vat_number_out_of_an_address(string $raw, string $expected, string $vat): void
    {
        $this->assertSame($vat, AddressText::taxNumberIn($raw));
        $this->assertSame($expected, AddressText::withoutTaxNumber($raw));
    }

    public static function addressesThatMustBeLeftAlone(): array
    {
        return [
            'a box number'            => ['P.O. Box 6377, Secunda, 2302'],
            'a street and a postcode' => ['Design Quarter, Leslie Rd, Fourways, Johannesburg, 2191'],
            'a long number, unlabelled' => ['Unit 4370228530 Industrial Park'],
            'a phone number'          => ['12 Leslie Rd, Tel: 017 689 2006'],
            'the word vat in a name'  => ['Vatican Close, Sandton, 2196'],
        ];
    }

    #[DataProvider('addressesThatMustBeLeftAlone')]
    public function test_it_leaves_an_ordinary_address_untouched(string $raw): void
    {
        $this->assertNull(AddressText::taxNumberIn($raw));
        $this->assertSame($raw, AddressText::withoutTaxNumber($raw));
    }

    public function test_an_empty_address_is_handled(): void
    {
        $this->assertNull(AddressText::taxNumberIn(null));
        $this->assertNull(AddressText::withoutTaxNumber(null));
        $this->assertSame('', AddressText::withoutTaxNumber(''));
    }

    public function test_an_address_that_is_nothing_but_a_vat_number_becomes_empty(): void
    {
        $this->assertNull(AddressText::withoutTaxNumber('VAT NO: 4370228530'));
    }
}
