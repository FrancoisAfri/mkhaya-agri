<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The practice's own invoicing details: who the invoice is from, and where to pay.
 *
 * One row per tenant, read only when an invoice is opened. Everything here is a *default* — the
 * values are copied onto the invoice and the invoice keeps them, so changing bank next year cannot
 * restate an invoice already issued.
 */
class InvoicingSetting extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_invoicing_settings';

    /**
     * Registered until somebody says otherwise.
     *
     * Declared here as well as on the column, because a row inserted through Eloquent leaves the
     * in-memory model with the attribute unset — the database default never reaches it — and
     * `defaults()` called on that instance would zero-rate an invoice for a registered practice.
     */
    protected $attributes = ['vat_registered' => true];

    protected $fillable = [
        'uuid', 'company_id',
        'consultant_name', 'consultant_address', 'consultant_reference',
        'consultant_vat_number', 'vendor_number',
        'banking_name', 'banking_account_name', 'banking_account_number', 'banking_branch_code',
        'vat_rate', 'disbursement_fee_rate', 'account_description',
        'vat_registered', 'implementing_agent', 'default_project_funder', 'report_recipient_ids', 'report_recipient_emails', 'updated_by',
    ];

    protected $casts = [
        'vat_rate'              => 'decimal:6',
        'disbursement_fee_rate' => 'decimal:6',
        'report_recipient_ids'  => 'array',
        'vat_registered'        => 'boolean',
        'implementing_agent'    => 'boolean',
    ];

    /**
     * Extra addresses, one per line, for people who are not Hub users.
     *
     * @return list<string>
     */
    public function extraRecipientEmails(): array
    {
        return collect(preg_split('/[\r\n,;]+/', (string) $this->report_recipient_emails))
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => filter_var($line, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();
    }

    /** The tenant's row, created empty on first use so a screen always has something to edit. */
    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    /** Whether invoices from this practice carry VAT. Unset reads as registered, per the default. */
    public function chargesVat(): bool
    {
        return (bool) ($this->vat_registered ?? true);
    }

    /**
     * The subset an invoice copies when it is opened.
     *
     * Empty values are dropped rather than written as blanks, so a half-filled Setup page does not
     * overwrite details that carried forward from the previous invoice.
     *
     * @return array<string, mixed>
     */
    public function defaultsForInvoice(): array
    {
        /*
         * The company's own details, from the one place that already holds them.
         *
         * This read only Project Management's invoicing settings, and on an installation where
         * nobody had filled those in every invoice went out with no practice name, no address and
         * no VAT number — the PDF fell back to config('app.name') for the sender and printed an
         * empty "To:". The details were not missing from the system; they were sitting in Company
         * Setup, where somebody had already typed them once.
         *
         * PM's own settings still win where they are set. A practice invoicing under a trading name
         * different from its registered one is a real arrangement and this is where it is
         * expressed; the company record is the fallback beneath it, not a replacement for it.
         */
        /*
         * PM's own values are filtered BEFORE the merge, not after.
         *
         * array_merge lets a later null overwrite an earlier value, so merging PM's unfilled
         * settings over the company record wiped every fallback and array_filter then dropped the
         * nulls — leaving exactly the blank invoice this was meant to fix. "Not set" has to be
         * absent from the array, not present and null.
         */
        $own = array_filter([
            'consultant_name'        => $this->consultant_name,
            'consultant_address'     => $this->consultant_address,
            'consultant_reference'   => $this->consultant_reference,
            'consultant_vat_number'  => $this->consultant_vat_number,
            'vendor_number'          => $this->vendor_number,
            'banking_name'           => $this->banking_name,
            'banking_account_name'   => $this->banking_account_name,
            'banking_account_number' => $this->banking_account_number,
            'banking_branch_code'    => $this->banking_branch_code,
            'description'            => $this->account_description,
            // A practice that is not VAT registered invoices at zero — stated once here rather
            // than assumed on every invoice. Both rates follow it, because the disbursement block
            // is VAT too (the workbook's 14% was a rate nobody had updated since 2018).
            'vat_rate'               => $this->chargesVat() ? $this->vat_rate : '0',
            'disbursement_fee_rate'  => $this->chargesVat() ? $this->vat_rate : '0',
        ], fn ($value) => $value !== null && $value !== '');

        return array_merge($this->companyDetails(), $own);
    }

    /**
     * Company Setup's answer to the same questions.
     *
     * Guarded on the table existing rather than on the module being enabled: Project Management
     * must not stop issuing invoices because Finance is not installed, and a missing table is the
     * honest test for that. A tenant without Finance falls through to PM's own settings, which is
     * exactly where it was before.
     *
     * @return array<string, mixed>
     */
    private function companyDetails(): array
    {
        if (! Schema::hasTable('fin_settings')) {
            return [];
        }

        $company = FinSetting::current();

        return array_filter([
            // The trading name is what a client recognises on an invoice; the registered name is
            // the fallback, and it is the one that appears on the VAT return.
            'consultant_name'        => $company->trading_name ?: $company->company_name,
            'consultant_address'     => $company->address,
            'consultant_vat_number'  => $company->vat_number,
            /*
             * No vendor number from the company.
             *
             * It used to fall back to the one on the accounting settings page — a single
             * value stamped onto every invoice to every client, which is the mistake that
             * page no longer offers. A vendor number is the supplier account one client
             * opened for us, so it is carried per invoice; there is nothing company-wide
             * left to fall back to, and inheriting a leftover value would be worse than
             * leaving the field blank for somebody to fill in.
             */
            'banking_name'           => $company->bank_name,
            'banking_account_name'   => $company->bank_account_name,
            'banking_account_number' => $company->bank_account_number,
            'banking_branch_code'    => $company->branch_code,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
