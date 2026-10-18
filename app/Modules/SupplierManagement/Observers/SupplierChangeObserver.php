<?php

namespace App\Modules\SupplierManagement\Observers;

use App\Modules\SupplierManagement\DueDiligence\Services\ChangeDetector;
use App\Modules\SupplierManagement\DueDiligence\Support\DueDiligenceAddon;
use App\Modules\SupplierManagement\Models\Supplier;

/**
 * Notices the changes that arrive by somebody typing them.
 *
 * The scheduled sweep catches what a source starts saying differently. This catches the other half —
 * the banking details edited on a Tuesday afternoon, which no external source will ever report and
 * which is the single most consequential field on the record. An email arrives on letterhead, a
 * clerk updates the account number, and the next payment run sends real money to a stranger; the
 * only trace is the edit itself, so the edit has to be the event.
 *
 * Deliberately does not block the save. Refusing an edit here would put the control in the wrong
 * place — BankingGate already stops the payment, which is where stopping matters — and a supplier
 * whose phone number cannot be corrected because the observer threw is a system people work around.
 */
class SupplierChangeObserver
{
    /** Fields worth an event, and what kind of change each represents. */
    private const WATCHED = [
        'bank_account_number' => 'banking',
        'account_holder_name' => 'banking',
        'bank_name' => 'banking',
        'branch_code' => 'banking',
        'company_name' => 'company_name',
        'company_registration_number' => 'company_name',
        'tax_reference_number' => 'tax',
        'vat_registration_number' => 'tax',
        'physical_address' => 'address',
        'bbbee_status_level' => 'bbbee',
    ];

    public function updated(Supplier $supplier): void
    {
        // Only where the add-on is on: a tenant without it has no change log to write to and no
        // screen that would show this.
        if (! DueDiligenceAddon::active()) {
            return;
        }

        $detector = app(ChangeDetector::class);

        foreach (self::WATCHED as $field => $category) {
            if (! $supplier->wasChanged($field)) {
                continue;
            }

            $detector->forEdit(
                $supplier,
                $category,
                $field,
                (string) $supplier->getOriginal($field),
                (string) $supplier->getAttribute($field),
                $category === 'banking'
                    // Said in the words of the risk rather than the words of the schema: the reader
                    // needs to know why they are being told.
                    ? 'Banking details were changed on the supplier record. Verify the account against the supplier before the next payment.'
                    : null,
            );
        }
    }
}
