<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPayment;
use App\Modules\Finance\Accounting\Models\FinPriceList;
use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\FiscalYearService;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\QuoteService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Migrate a business out of Sage, Xero or Zoho by uploading that system's own CSV/XLSX exports:
 * master data (customers, suppliers, items), documents (invoices, bills, quotes), raw general-
 * ledger transactions, and customer statements (brought in as opening balances). Each system
 * labels its columns differently; this service knows those layouts and maps them onto our fields,
 * so a user just picks their old system, picks what they're importing, and drops in the file.
 * Preview first, then commit. Tenant-scoped: new rows land in the current company via RLS.
 */
class ExternalImportService
{
    public const SYSTEMS = ['sage', 'xero', 'zoho', 'generic'];
    public const ENTITIES = ['accounts', 'customers', 'suppliers', 'items', 'invoices', 'bills', 'quotes', 'customer_transactions', 'transactions', 'statements'];

    /** Report lines inside a customer's block that are totals, not transactions. */
    private const LEDGER_TOTAL_LINES = ['opening balance', 'closing balance', 'movement for the period', 'grand total', 'total'];

    /**
     * Sage groups accounts by category rather than typing them; this is that category mapped onto
     * our five ledger types. The category itself is kept as the account's subtype, so an income
     * statement can still separate cost of sales from overheads.
     */
    private const CATEGORY_TYPES = [
        'currentassets' => 'asset', 'noncurrentassets' => 'asset', 'fixedassets' => 'asset', 'assets' => 'asset', 'bank' => 'asset',
        'currentliabilities' => 'liability', 'noncurrentliabilities' => 'liability', 'longtermliabilities' => 'liability', 'liabilities' => 'liability',
        'ownersequity' => 'equity', 'equity' => 'equity', 'capital' => 'equity', 'retainedearnings' => 'equity',
        'sales' => 'income', 'income' => 'income', 'otherincome' => 'income', 'revenue' => 'income',
        'costofsales' => 'expense', 'expenses' => 'expense', 'otherexpenses' => 'expense', 'incometax' => 'expense', 'overheads' => 'expense',
    ];

    /**
     * Where a generated code starts per type. Deliberately matches accountTypeForCode()'s
     * reading of a code (1x asset, 2x liability, 3x equity, 4-5x income, else expense), so an
     * account we number here is typed the same way if it ever comes back through another import.
     */
    private const CODE_BANDS = ['asset' => 1000, 'liability' => 2000, 'equity' => 3000, 'income' => 4000, 'expense' => 6000];

    /** Entities where each source row is a document LINE, grouped by document number. */
    private const GROUPED = ['invoices', 'bills', 'quotes'];

    public function __construct(
        private ArApService $arap,
        private QuoteService $quotes,
        private FiscalYearService $fiscalYears,
        private CurrentCompany $currentCompany,
        private Ledger $ledger,
    ) {
    }

    /**
     * Per system + entity: our field => candidate source column names (any that matches wins).
     * Names are matched after normalising (lowercase, non-alphanumeric → underscore).
     */
    private function profiles(): array
    {
        $party = [
            'name'                => ['xero' => ['contact_name', 'name'], 'zoho' => ['display_name', 'company_name', 'contact_name'], 'sage' => ['name', 'customer', 'supplier', 'account_name'], 'generic' => ['name', 'company', 'contact']],
            // Sage keeps the person you deal with separate from the account name.
            'contact_name'        => ['xero' => ['contact_person', 'primary_person_first_name'], 'zoho' => ['contact_name', 'primary_contact'], 'sage' => ['contact_name', 'contact_person', 'contact'], 'generic' => ['contact_name', 'contact_person']],
            'email'               => ['xero' => ['email_address', 'email'], 'zoho' => ['email_id', 'email'], 'sage' => ['email_address', 'email', 'e_mail'], 'generic' => ['email', 'email_address']],
            'website'             => ['xero' => ['website'], 'zoho' => ['website'], 'sage' => ['web_address', 'website'], 'generic' => ['website', 'web_address', 'url']],
            'phone'               => ['xero' => ['phone_number', 'phone'], 'zoho' => ['phone', 'mobile_phone', 'mobilephone'], 'sage' => ['telephone_number', 'telephone', 'phone', 'tel', 'cell_number'], 'generic' => ['phone', 'telephone', 'telephone_number', 'mobile']],
            'fax'                 => ['xero' => ['fax_number'], 'zoho' => ['fax'], 'sage' => ['fax_number', 'fax'], 'generic' => ['fax', 'fax_number']],
            'vat_number'          => ['xero' => ['tax_number', 'vat_number'], 'zoho' => ['gst_vat_number', 'tax_id', 'vat_registration_number'], 'sage' => ['vat_reference', 'vat_number', 'vat_reg_number', 'vat_registration'], 'generic' => ['vat_number', 'vat', 'vat_reference', 'tax_number']],
            'registration_number' => ['xero' => ['company_number', 'registration_number'], 'zoho' => ['company_id', 'registration_number'], 'sage' => ['registration_number', 'company_registration', 'company_registration_number'], 'generic' => ['registration_number', 'reg_number']],
            'address'             => ['xero' => ['po_address_line_1', 'po_address_line1', 'street_address'], 'zoho' => ['billing_address', 'billing_street', 'billing_street2'], 'sage' => ['address_1', 'address1', 'street'], 'generic' => ['address', 'street']],
            'city'                => ['xero' => ['po_city'], 'zoho' => ['billing_city'], 'sage' => ['city', 'town'], 'generic' => ['city', 'town']],
            'country'             => ['xero' => ['po_country'], 'zoho' => ['billing_country'], 'sage' => ['country'], 'generic' => ['country']],
            'code'                => ['xero' => ['account_number'], 'zoho' => ['contact_id'], 'sage' => ['account_reference', 'account_ref', 'a_c', 'customer_code', 'supplier_code'], 'generic' => ['code', 'reference', 'account']],
            'category'            => ['xero' => [], 'zoho' => ['customer_sub_type'], 'sage' => ['category', 'customer_category', 'group'], 'generic' => ['category', 'group']],
            'credit_limit'        => ['xero' => [], 'zoho' => ['credit_limit'], 'sage' => ['credit_limit'], 'generic' => ['credit_limit']],
            'credit_terms_days'   => ['xero' => ['due_date_day_of_month'], 'zoho' => ['payment_terms'], 'sage' => ['payment_terms', 'credit_terms', 'terms_days'], 'generic' => ['payment_terms', 'credit_terms', 'terms']],
            'sales_rep'           => ['xero' => [], 'zoho' => ['sales_person'], 'sage' => ['sales_rep', 'salesperson', 'rep'], 'generic' => ['sales_rep', 'salesperson']],
            'default_discount_percent' => ['xero' => ['discount'], 'zoho' => ['discount'], 'sage' => ['default_discount_percentage', 'default_discount', 'discount_percentage'], 'generic' => ['discount', 'discount_percentage']],
            'is_active'           => ['xero' => [], 'zoho' => ['status'], 'sage' => ['active'], 'generic' => ['active', 'is_active']],
            // Linked to an existing price list by name; we never create one from an import.
            'price_list'          => ['xero' => [], 'zoho' => ['price_list'], 'sage' => ['default_price_list', 'price_list'], 'generic' => ['price_list']],
        ];

        $items = [
            'code'        => ['xero' => ['item_code', 'code'], 'zoho' => ['sku', 'item_id'], 'sage' => ['stock_code', 'product_code', 'code'], 'generic' => ['code', 'sku', 'item_code']],
            'name'        => ['xero' => ['item_name', 'name', 'description'], 'zoho' => ['item_name', 'name'], 'sage' => ['description', 'name', 'item_name'], 'generic' => ['name', 'item', 'description']],
            'description' => ['xero' => ['sales_description', 'purchases_description', 'description'], 'zoho' => ['description'], 'sage' => ['description', 'details'], 'generic' => ['description', 'details']],
            // Sage's item export prices the line as "Price Exclusive"/"Price Inclusive"; take the
            // exclusive one first — our sales_price is exclusive of VAT and tax_rate is added on top.
            'sales_price' => ['xero' => ['sales_unit_price', 'unit_price'], 'zoho' => ['rate', 'selling_price'], 'sage' => ['price_exclusive', 'price_excl', 'selling_price_exclusive', 'sales_price', 'price_inclusive', 'price_incl', 'price', 'unit_price'], 'generic' => ['sales_price', 'selling_price', 'price_exclusive', 'price_inclusive', 'price', 'rate', 'unit_price']],
            'cost_price'  => ['xero' => ['purchases_unit_price', 'cost'], 'zoho' => ['purchase_rate', 'cost_price'], 'sage' => ['cost_price', 'cost_exclusive', 'average_cost', 'latest_cost', 'cost'], 'generic' => ['cost_price', 'cost']],
            'unit'        => ['xero' => ['unit'], 'zoho' => ['usage_unit', 'unit'], 'sage' => ['unit', 'uom'], 'generic' => ['unit', 'uom']],
            'tax_rate'    => ['xero' => ['tax_rate'], 'zoho' => ['tax_percentage', 'tax_rate'], 'sage' => ['tax_rate', 'vat_rate'], 'generic' => ['tax_rate', 'vat_rate']],
        ];

        // Document (invoice/bill/quote) exports: one row per line, grouped by number.
        $doc = [
            'number'       => ['xero' => ['invoice_number', 'quote_number'], 'zoho' => ['invoice_number', 'estimate_number', 'quote_number'], 'sage' => ['invoice_no', 'number', 'document_no', 'document', 'reference', 'ref', 'doc_no', 'inv_no'], 'generic' => ['number', 'invoice_number', 'doc_number', 'document', 'reference']],
            'contact'      => ['xero' => ['contact_name'], 'zoho' => ['customer_name', 'company_name', 'vendor_name'], 'sage' => ['name', 'account', 'customer', 'customer_name', 'account_name', 'client', 'supplier', 'supplier_name'], 'generic' => ['customer', 'customer_name', 'supplier', 'contact', 'client', 'name']],
            'date'         => ['xero' => ['invoice_date', 'date'], 'zoho' => ['invoice_date', 'estimate_date', 'date'], 'sage' => ['date', 'invoice_date'], 'generic' => ['date', 'invoice_date', 'document_date']],
            'due_date'     => ['xero' => ['due_date'], 'zoho' => ['due_date'], 'sage' => ['due_date'], 'generic' => ['due_date']],
            'valid_until'  => ['xero' => ['expiry_date'], 'zoho' => ['expiry_date'], 'sage' => ['valid_until', 'expiry_date'], 'generic' => ['valid_until', 'expiry_date']],
            'description'  => ['xero' => ['description'], 'zoho' => ['item_description', 'description'], 'sage' => ['description', 'details'], 'generic' => ['description', 'details', 'item']],
            'quantity'     => ['xero' => ['quantity'], 'zoho' => ['quantity'], 'sage' => ['quantity', 'qty'], 'generic' => ['quantity', 'qty']],
            'unit_price'   => ['xero' => ['unit_amount', 'unit_price'], 'zoho' => ['item_price', 'rate'], 'sage' => ['unit_price', 'price'], 'generic' => ['unit_price', 'price', 'rate']],
            // The document's gross value. Sage's invoice report calls it "Total Selling".
            'total'        => ['xero' => ['total', 'invoice_amount', 'line_amount'], 'zoho' => ['total', 'invoice_total', 'grand_total', 'balance'], 'sage' => ['total_selling', 'total_including_vat', 'total_incl_vat', 'gross', 'total', 'net_amount'], 'generic' => ['total', 'amount', 'grand_total', 'amount_due']],
            // Gross split into its parts, when the export gives both — used to recover the VAT
            // rate so the journal debits revenue and VAT control separately, as it should.
            'net'          => ['xero' => ['subtotal'], 'zoho' => ['sub_total', 'subtotal'], 'sage' => ['exclusive', 'total_excluding_vat', 'total_excl_vat', 'net'], 'generic' => ['net', 'subtotal', 'exclusive']],
            'tax_amount'   => ['xero' => ['total_tax'], 'zoho' => ['tax_total', 'total_tax'], 'sage' => ['vat', 'vat_amount', 'tax_amount'], 'generic' => ['vat_amount', 'tax_amount', 'vat']],
            'account_code' => ['xero' => ['account_code'], 'zoho' => ['account', 'account_code'], 'sage' => ['nominal_code', 'account_code'], 'generic' => ['account_code', 'account']],
            'tax_rate'     => ['xero' => ['tax_rate'], 'zoho' => ['tax_percentage', 'tax_rate'], 'sage' => ['tax_rate', 'vat_rate'], 'generic' => ['tax_rate', 'vat_rate']],
            'item_code'    => ['xero' => ['item_code'], 'zoho' => ['sku', 'item_id'], 'sage' => ['stock_code', 'product_code'], 'generic' => ['item_code', 'sku']],
        ];

        // General-ledger / transaction exports: one row per journal line, grouped by reference.
        $txn = [
            'number'       => ['xero' => ['journal_number', 'reference', 'source'], 'zoho' => ['journal_number', 'reference', 'transaction_id'], 'sage' => ['reference', 'ref', 'transaction_no', 'journal_no', 'audit_no', 'no'], 'generic' => ['reference', 'number', 'journal', 'transaction', 'ref']],
            'date'         => ['xero' => ['date', 'journal_date'], 'zoho' => ['journal_date', 'date', 'transaction_date'], 'sage' => ['date', 'transaction_date'], 'generic' => ['date', 'transaction_date']],
            'account_code' => ['xero' => ['account_code'], 'zoho' => ['account_code', 'account'], 'sage' => ['nominal_code', 'account_code', 'nominal', 'n_c'], 'generic' => ['account_code', 'nominal_code', 'account_no']],
            'account_name' => ['xero' => ['account', 'account_name'], 'zoho' => ['account_name', 'account'], 'sage' => ['account_name', 'nominal_name', 'name'], 'generic' => ['account_name', 'account']],
            'description'  => ['xero' => ['description', 'narration'], 'zoho' => ['description', 'notes'], 'sage' => ['details', 'description', 'narrative'], 'generic' => ['description', 'details', 'narration', 'memo']],
            'debit'        => ['xero' => ['debit'], 'zoho' => ['debit'], 'sage' => ['debit'], 'generic' => ['debit', 'dr']],
            'credit'       => ['xero' => ['credit'], 'zoho' => ['credit'], 'sage' => ['credit'], 'generic' => ['credit', 'cr']],
            'amount'       => ['xero' => ['amount', 'net_amount'], 'zoho' => ['amount'], 'sage' => ['amount', 'value', 'net'], 'generic' => ['amount', 'value']],
        ];

        // Customer statement / opening-balance exports: one row per outstanding item or per customer.
        $statement = [
            'contact'  => $doc['contact'],
            'number'   => $doc['number'],
            'date'     => $doc['date'],
            'due_date' => $doc['due_date'],
            'total'    => ['xero' => ['balance', 'due', 'amount_due', 'outstanding', 'total'], 'zoho' => ['balance', 'balance_due', 'outstanding', 'total'], 'sage' => ['balance', 'outstanding', 'amount', 'gross'], 'generic' => ['balance', 'outstanding', 'amount_due', 'amount', 'total']],
        ];

        // Chart of accounts. Sage's account list has no code column — accounts are identified by
        // name — so one is generated per type band when the file doesn't carry one.
        $accounts = [
            'name'                 => ['xero' => ['name', 'account_name'], 'zoho' => ['account_name', 'name'], 'sage' => ['name', 'account_name', 'description'], 'generic' => ['name', 'account_name']],
            'code'                 => ['xero' => ['code', 'account_code'], 'zoho' => ['account_code', 'code'], 'sage' => ['code', 'account_code', 'nominal_code'], 'generic' => ['code', 'account_code', 'nominal_code']],
            'category'             => ['xero' => ['type', 'account_type'], 'zoho' => ['account_type', 'type'], 'sage' => ['category', 'account_category', 'type'], 'generic' => ['category', 'type', 'account_type']],
            'description'          => ['xero' => ['description'], 'zoho' => ['description'], 'sage' => ['description'], 'generic' => ['description']],
            'tax_code'             => ['xero' => ['tax_code'], 'zoho' => ['tax_type'], 'sage' => ['default_vat_type', 'vat_type', 'tax_code'], 'generic' => ['tax_code', 'vat_type']],
            'is_active'            => ['xero' => [], 'zoho' => ['status'], 'sage' => ['active'], 'generic' => ['active', 'is_active']],
            'opening_balance'      => ['xero' => [], 'zoho' => ['opening_balance'], 'sage' => ['opening_balance'], 'generic' => ['opening_balance']],
            'opening_balance_date' => ['xero' => [], 'zoho' => ['opening_balance_date'], 'sage' => ['opening_balance_date'], 'generic' => ['opening_balance_date']],
        ];

        /*
         * A customer ledger (Sage's Customer Transactions Report). Not a general ledger: there's
         * no account column, and the first column carries BOTH the customer's name — on its own
         * row above each block — and the date of each transaction underneath it.
         */
        $ledger = [
            'date'        => ['xero' => ['date'], 'zoho' => ['date'], 'sage' => ['customer_date', 'date', 'transaction_date'], 'generic' => ['customer_date', 'date']],
            'number'      => ['xero' => ['reference'], 'zoho' => ['reference'], 'sage' => ['reference', 'ref', 'document_no'], 'generic' => ['reference', 'number', 'document_no']],
            'type'        => ['xero' => ['type'], 'zoho' => ['transaction_type'], 'sage' => ['transaction_type', 'type'], 'generic' => ['transaction_type', 'type']],
            'contact'     => ['xero' => ['contact_name'], 'zoho' => ['customer_name'], 'sage' => ['customer', 'customer_name', 'account_name'], 'generic' => ['customer', 'customer_name']],
            'description' => ['xero' => ['description'], 'zoho' => ['description'], 'sage' => ['description', 'details'], 'generic' => ['description', 'details']],
            'debit'       => ['xero' => ['debit'], 'zoho' => ['debit'], 'sage' => ['debit'], 'generic' => ['debit', 'dr']],
            'credit'      => ['xero' => ['credit'], 'zoho' => ['credit'], 'sage' => ['credit'], 'generic' => ['credit', 'cr']],
            'balance'     => ['xero' => ['balance'], 'zoho' => ['balance'], 'sage' => ['balance'], 'generic' => ['balance', 'running_balance']],
        ];

        return [
            'accounts'     => $accounts,
            'customer_transactions' => $ledger,
            'customers'    => $party,
            'suppliers'    => $party,
            'items'        => $items,
            'invoices'     => $doc,
            'bills'        => $doc,
            'quotes'       => $doc,
            'transactions' => $txn,
            'statements'   => $statement,
        ];
    }

    /**
     * Fields whose source is spread across SEVERAL columns. A customer master file writes the
     * address as "Postal Address Line 1..4" + a postal code column, which the one-field-one-column
     * mapping above can't express — every one of those columns ends up ignored. Any of these that
     * the file actually has are joined, in order, into a single value.
     */
    private function groupProfiles(): array
    {
        $addresses = [
            'postal_address' => [
                'sage'    => ['postal_address_line_1', 'postal_address_line_2', 'postal_address_line_3', 'postal_address_line_4', 'postal_address_postal_code'],
                'xero'    => ['po_address_line_1', 'po_address_line_2', 'po_address_line_3', 'po_address_line_4', 'po_postal_code'],
                'zoho'    => ['billing_address', 'billing_street2', 'billing_code'],
                'generic' => ['postal_address_line_1', 'postal_address_line_2', 'postal_address_line_3', 'postal_address_line_4', 'postal_code'],
            ],
            // The physical/delivery address, kept apart from the postal one.
            'address' => [
                'sage'    => ['delivery_address_line_1', 'delivery_address_line_2', 'delivery_address_line_3', 'delivery_address_line_4', 'delivery_address_postal_code'],
                'xero'    => ['sa_address_line_1', 'sa_address_line_2', 'sa_address_line_3', 'sa_address_line_4', 'sa_postal_code'],
                'zoho'    => ['shipping_address', 'shipping_street2', 'shipping_code'],
                'generic' => ['address_line_1', 'address_line_2', 'address_line_3', 'address_line_4'],
            ],
        ];

        return ['customers' => $addresses, 'suppliers' => $addresses];
    }

    /** Preview: headers, the field→column mapping we detected, a sample and the row count. */
    public function preview(UploadedFile $file, string $system, string $entity): array
    {
        [$headers, $rows] = $this->read($file);
        $map = $this->buildMap($headers, $system, $entity);

        $sample = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            $sample[] = $this->mapRow($row, $map);
        }

        $grouped = in_array($entity, self::GROUPED, true);
        $docCount = $grouped && isset($map['number'])
            ? count(array_unique(array_filter(array_map(fn ($r) => trim((string) ($r[$map['number']] ?? '')), $rows))))
            : null;

        // A grouped field holds a list of headers, so flatten before working out what was ignored.
        $used = [];
        foreach ($map as $header) {
            foreach ((array) $header as $h) {
                $used[] = $h;
            }
        }

        return [
            'headers'   => $headers,
            'mapped'    => $map,                                  // our_field => source_header(s)
            'unmapped'  => array_values(array_diff($headers, $used)),
            'sample'    => $sample,
            'rows'      => count($rows),
            'grouped'   => $grouped,
            'documents' => $docCount,                            // distinct docs (grouped entities)
        ];
    }

    /** Commit the import. Returns ['imported'=>n,'updated'=>n,'skipped'=>[...]]. */
    public function import(UploadedFile $file, string $system, string $entity): array
    {
        [$headers, $rows] = $this->read($file);
        $map = $this->buildMap($headers, $system, $entity);

        if (in_array($entity, self::GROUPED, true)) {
            return $this->importDocuments($rows, $map, $entity);
        }
        if ($entity === 'customer_transactions') {
            return $this->importCustomerLedger($rows, $map);
        }
        if ($entity === 'transactions') {
            return $this->importTransactions($rows, $map);
        }
        if ($entity === 'statements') {
            return $this->importStatements($rows, $map);
        }

        if ($entity === 'accounts') {
            return $this->importAccounts($rows, $map);
        }

        return $entity === 'items'
            ? $this->importItems($rows, $map)
            : $this->importParties($rows, $map, $entity === 'suppliers' ? 'supplier' : 'customer');
    }

    /**
     * Full re-post of a general ledger: group journal-line rows by reference and post each as a
     * balanced GL journal through the ledger. Handles debit/credit columns or a single signed
     * amount; resolves each line's account by code (else name), auto-creating a postable account
     * — typed by its code range — when the chart of accounts doesn't have it yet; and balances
     * any rounding/one-sided remainder to a migration suspense account. Idempotent per reference
     * so a re-run doesn't double-post.
     */
    private function importTransactions(array $rows, array $map): array
    {
        // Group rows into journals by reference; ungrouped rows each become their own journal.
        $groups = [];
        $seq = 0;
        foreach ($rows as $r) {
            $ref = isset($map['number']) ? trim((string) ($r[$map['number']] ?? '')) : '';
            $key = $ref !== '' ? $ref : ('row_' . (++$seq));
            $groups[$key][] = $r;
        }

        $imported = 0;
        $skipped = [];
        foreach ($groups as $ref => $group) {
            $head = $group[0];
            $date = $this->parseDate(isset($map['date']) ? ($head[$map['date']] ?? null) : null);
            if ($date === null) {
                $skipped[] = ['row' => $ref, 'reason' => 'No valid transaction date.'];
                continue;
            }

            $debits = [];
            $credits = [];
            foreach ($group as $r) {
                $accountId = $this->resolveGlAccount($map, $r);
                if ($accountId === null) {
                    continue;
                }
                $narration = $this->s(isset($map['description']) ? ($r[$map['description']] ?? null) : null) ?? ('Imported transaction ' . $ref);
                $dr = $this->num(isset($map['debit']) ? ($r[$map['debit']] ?? null) : null) ?? 0.0;
                $cr = $this->num(isset($map['credit']) ? ($r[$map['credit']] ?? null) : null) ?? 0.0;
                if ($dr == 0.0 && $cr == 0.0) {
                    // No debit/credit columns — fall back to a single signed amount (+ = debit).
                    $amount = $this->num(isset($map['amount']) ? ($r[$map['amount']] ?? null) : null) ?? 0.0;
                    if ($amount > 0) {
                        $dr = $amount;
                    } elseif ($amount < 0) {
                        $cr = abs($amount);
                    }
                }
                if ($dr > 0) {
                    $debits[] = ['account' => $accountId, 'amount' => round($dr, 2), 'narration' => $narration];
                }
                if ($cr > 0) {
                    $credits[] = ['account' => $accountId, 'amount' => round($cr, 2), 'narration' => $narration];
                }
            }

            if ($debits === [] && $credits === []) {
                $skipped[] = ['row' => $ref, 'reason' => 'No debit/credit amounts (or account) found on this transaction.'];
                continue;
            }

            // Balance any remainder to the migration suspense account.
            $diff = round(array_sum(array_column($debits, 'amount')) - array_sum(array_column($credits, 'amount')), 2);
            if (abs($diff) >= 0.01) {
                $suspense = $this->suspenseAccountId();
                if ($diff > 0) {
                    $credits[] = ['account' => $suspense, 'amount' => $diff, 'narration' => 'Migration balancing (suspense)'];
                } else {
                    $debits[] = ['account' => $suspense, 'amount' => abs($diff), 'narration' => 'Migration balancing (suspense)'];
                }
            }

            $this->ensurePeriodFor($date);
            try {
                $this->ledger->post(new FinancialEvent(
                    sourceModule: 'import',
                    eventType: 'gl.import',
                    debits: $debits,
                    credits: $credits,
                    eventDate: $date,
                    companyId: $this->currentCompany->id(),
                    sourceRef: 'import:txn:' . $ref,
                    description: 'Imported transaction ' . $ref,
                    idempotencyKey: 'import_txn:' . (int) ($this->currentCompany->id() ?? 0) . ':' . $ref . ':' . $date,
                ));
                $imported++;
            } catch (\Throwable $e) {
                Log::warning('External import: transaction failed', ['ref' => $ref, 'error' => $e->getMessage()]);
                $skipped[] = ['row' => $ref, 'reason' => $e->getMessage()];
            }
        }

        return ['imported' => $imported, 'updated' => 0, 'skipped' => $skipped];
    }

    /**
     * Replay a customer ledger (Sage's Customer Transactions Report) as the debtors book: each
     * invoice is re-raised, each credit note re-issued, and each receipt allocated against that
     * customer's open invoices oldest-first — so both the sales history AND the ageing come out
     * right, which an invoice-only import can't do (it shows everything as still owing).
     *
     * The file's shape is a report, not a table: the customer's name sits alone in the date
     * column above its block, and Opening/Closing/Movement lines are totals rather than
     * transactions. A non-zero opening balance becomes an opening-balance invoice so the
     * customer starts where Sage had them.
     *
     * Receipts post to a migration clearing account rather than a real bank: the money is
     * already in your bank history, and this keeps the migration in one account you reconcile
     * once. Cash that arrives with nothing open to settle (the customer paid before the invoice
     * we've seen, or the invoice predates the report) is carried as an unallocated credit, so
     * the customer's balance is still right.
     *
     * Re-runs are skipped per reference, so this and the invoice import can't double-count.
     */
    private function importCustomerLedger(array $rows, array $map): array
    {
        $column = $map['date'] ?? null;   // the column holding both customer names and dates
        if ($column === null) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => [['row' => 1, 'reason' => 'Could not find the customer/date column to read this ledger by.']]];
        }

        $this->migrationClearingAccountId();          // exists before anything posts to it by code
        $clearing = $this->migrationClearingCode();
        $imported = 0;
        $skipped = [];
        $customer = null;
        $seq = 0;

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $first = trim((string) ($row[$column] ?? ''));
            if ($first === '') {
                continue;
            }

            $date = $this->parseDate($first);
            if ($date === null) {
                // Not a transaction row: either a customer's name, or one of the report's totals.
                if (! $this->isLedgerTotalLine($first)) {
                    $customer = $first;
                } elseif (str_starts_with(strtolower($first), 'opening balance')) {
                    $this->openingBalanceLine($customer, $row, $map, $first, $line, $imported, $skipped);
                }
                continue;
            }

            if ($customer === null) {
                $skipped[] = ['row' => $line, 'reason' => 'Transaction appears before any customer name.'];
                continue;
            }

            $debit = $this->num(isset($map['debit']) ? ($row[$map['debit']] ?? null) : null) ?? 0.0;
            $credit = $this->num(isset($map['credit']) ? ($row[$map['credit']] ?? null) : null) ?? 0.0;
            if ($debit == 0.0 && $credit == 0.0) {
                continue;   // a note line with no money on it
            }

            $ref = $this->s(isset($map['number']) ? ($row[$map['number']] ?? null) : null)
                ?? ('LED-' . strtoupper(substr(md5($customer), 0, 6)) . '-' . (++$seq));
            $type = strtolower((string) $this->s(isset($map['type']) ? ($row[$map['type']] ?? null) : null));
            $narration = $this->s(isset($map['description']) ? ($row[$map['description']] ?? null) : null);

            try {
                $party = $this->partyByName($customer, 'customer');
                $this->ensurePeriodFor($date);

                if ($debit > 0) {
                    // Money owed to us — an invoice (a debit note reads the same way here).
                    if (FinInvoice::where('party_ref', $ref)->exists()) {
                        $skipped[] = ['row' => $line, 'reason' => 'Already imported.'];
                        continue;
                    }
                    $this->arap->createInvoice([
                        'party_id'      => $party->id,
                        'direction'     => 'receivable',
                        'invoice_date'  => $date,
                        'due_date'      => $date,
                        'party_ref'     => $ref,
                        'lines'         => [$this->ledgerLine($map, $row, $narration ?? ('Imported ' . $ref), $debit)],
                        'relieve_stock' => false,
                    ]);
                    $imported++;
                    continue;
                }

                // Money off the account — a credit note, or cash received.
                if (str_contains($type, 'credit')) {
                    if (FinInvoice::where('party_ref', $ref)->exists()) {
                        $skipped[] = ['row' => $line, 'reason' => 'Already imported.'];
                        continue;
                    }
                    $this->arap->importCreditNote([
                        'party_id'    => $party->id,
                        'credit_date' => $date,
                        'party_ref'   => $ref,
                        'description' => $narration ?? ('Imported credit note ' . $ref),
                        'lines'       => [$this->ledgerLine($map, $row, $narration ?? ('Imported credit note ' . $ref), $credit)],
                    ]);
                    $imported++;
                    continue;
                }

                if (FinPayment::where('reference', $ref)->exists()) {
                    $skipped[] = ['row' => $line, 'reason' => 'Already imported.'];
                    continue;
                }
                $this->receiptFor($party, $credit, $ref, $date, $clearing, $narration);
                $imported++;
            } catch (\Throwable $e) {
                Log::warning('External import: customer ledger row failed', ['ref' => $ref, 'error' => $e->getMessage()]);
                $skipped[] = ['row' => $line, 'reason' => $e->getMessage()];
            }
        }

        return ['imported' => $imported, 'updated' => 0, 'skipped' => $skipped];
    }

    /** A customer's opening balance at the start of the report becomes an opening-balance invoice. */
    private function openingBalanceLine(?string $customer, array $row, array $map, string $label, int $line, int &$imported, array &$skipped): void
    {
        $debit = $this->num(isset($map['debit']) ? ($row[$map['debit']] ?? null) : null) ?? 0.0;
        $credit = $this->num(isset($map['credit']) ? ($row[$map['credit']] ?? null) : null) ?? 0.0;
        $amount = round($debit - $credit, 2);
        if ($customer === null || abs($amount) < 0.01) {
            return;   // the usual case: opened at zero
        }

        $ref = 'OB-' . strtoupper(substr(md5($customer), 0, 8));
        if (FinInvoice::where('party_ref', $ref)->exists()) {
            return;
        }

        // Date lives in the label itself: "Opening Balance as at:  03/01/2022".
        preg_match('/(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})/', $label, $m);
        $date = $this->parseDate($m[1] ?? null);
        $this->ensurePeriodFor($date);

        $lines = [[
            'account_id'  => $this->openingBalanceAccountId(),
            'description' => 'Opening balance',
            'quantity'    => 1,
            'unit_price'  => abs($amount),
            'tax_rate'    => 0,
        ]];

        try {
            $party = $this->partyByName($customer, 'customer');
            if ($amount > 0) {
                $this->arap->createInvoice([
                    'party_id' => $party->id, 'direction' => 'receivable', 'invoice_date' => $date,
                    'due_date' => $date, 'party_ref' => $ref, 'lines' => $lines, 'relieve_stock' => false,
                ]);
            } else {
                $this->arap->importCreditNote([
                    'party_id' => $party->id, 'credit_date' => $date, 'party_ref' => $ref,
                    'description' => 'Opening balance (credit)', 'lines' => $lines,
                ]);
            }
            $imported++;
        } catch (\Throwable $e) {
            $skipped[] = ['row' => $line, 'reason' => $e->getMessage()];
        }
    }

    /** One invoice/credit-note line at the ledger's amount, on the file's account or the default. */
    private function ledgerLine(array $map, array $row, string $description, float $amount): array
    {
        return [
            'account_id'  => $this->resolveAccount($map, $row, 'receivable'),
            'description' => $description,
            'quantity'    => 1,
            'unit_price'  => abs($amount),
            'tax_rate'    => 0,   // the ledger shows the gross movement; VAT was split on the original
        ];
    }

    /**
     * Settle a receipt against the customer's open invoices, oldest first. Anything left over —
     * cash with no invoice to sit against — is carried as an unallocated credit against the
     * clearing account, which keeps the customer's balance right either way.
     */
    private function receiptFor(FinParty $party, float $amount, string $ref, ?string $date, string $clearingCode, ?string $narration): void
    {
        $remaining = round(abs($amount), 2);
        $allocations = [];

        // Invoices only — a credit note is also an open receivable row, and settling cash
        // against one would both overstate the balance and double-count the credit.
        $open = FinInvoice::where('party_id', $party->id)->where('direction', 'receivable')
            ->where('doc_type', 'invoice')
            ->whereIn('status', ['open', 'part_paid'])->orderBy('invoice_date')->orderBy('id')->get();

        foreach ($open as $invoice) {
            $outstanding = round((float) $invoice->outstanding(), 2);
            if ($outstanding <= 0) {
                continue;
            }
            $take = min($remaining, $outstanding);
            if ($take > 0.005) {
                $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $take];
                $remaining = round($remaining - $take, 2);
            }
            if ($remaining <= 0.005) {
                break;
            }
        }

        if ($allocations !== []) {
            $this->arap->recordPayment([
                'party_id'        => $party->id,
                'direction'       => 'receipt',
                'payment_date'    => $date,
                'bank_account_id' => $clearingCode,
                'reference'       => $ref,
                'allocations'     => $allocations,
            ]);
        }

        if ($remaining > 0.005) {
            $this->arap->importCreditNote([
                'party_id'    => $party->id,
                'credit_date' => $date,
                'party_ref'   => $ref . ($allocations !== [] ? '-UN' : ''),
                'description' => $narration ?? ('Unallocated receipt ' . $ref),
                'lines'       => [[
                    'account_id'  => $this->migrationClearingAccountId(),
                    'description' => 'Receipt on account ' . $ref,
                    'quantity'    => 1,
                    'unit_price'  => $remaining,
                    'tax_rate'    => 0,
                ]],
            ]);
        }
    }

    private function isLedgerTotalLine(string $value): bool
    {
        $value = strtolower(trim($value));
        foreach (self::LEDGER_TOTAL_LINES as $needle) {
            if (str_starts_with($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private ?int $clearingId = null;

    /**
     * Where migrated cash lands. Not a real bank account — those deposits are already in the
     * bank history you'll import separately; this is one account to reconcile the migration
     * against, and it should come out at nil once you do.
     */
    private function migrationClearingAccountId(): int
    {
        if ($this->clearingId !== null) {
            return $this->clearingId;
        }
        $cid = $this->currentCompany->id() ?? auth()->user()?->company_id;
        $account = FinAccount::firstOrCreate(
            ['company_id' => $cid, 'code' => $this->migrationClearingCode()],
            ['name' => 'Migration Clearing', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true],
        );
        if (! $account->is_postable || ! $account->is_active) {
            $account->update(['is_postable' => true, 'is_active' => true]);
        }

        return $this->clearingId = (int) $account->id;
    }

    private function migrationClearingCode(): string
    {
        return (string) config('finance.migration_clearing', '9998');
    }

    /** Resolve (or auto-create) the postable ledger account for a GL line. */
    private function resolveGlAccount(array $map, array $row): ?int
    {
        $cid = $this->currentCompany->id() ?? auth()->user()?->company_id;
        $scoped = fn ($q) => $cid ? $q->where('company_id', $cid) : $q;

        $code = isset($map['account_code']) ? trim((string) ($row[$map['account_code']] ?? '')) : '';
        $name = isset($map['account_name']) ? trim((string) ($row[$map['account_name']] ?? '')) : '';

        if ($code !== '') {
            $id = $scoped(FinAccount::where('code', $code)->where('is_postable', true)->where('is_active', true))->value('id');
            if ($id) {
                return (int) $id;
            }
            return $this->createGlAccount($code, $name !== '' ? $name : ('Imported ' . $code), $cid);
        }
        if ($name !== '') {
            $id = $scoped(FinAccount::where('name', $name)->where('is_postable', true)->where('is_active', true))->value('id');
            if ($id) {
                return (int) $id;
            }
            return $this->createGlAccount('IMP' . substr(md5($name), 0, 5), $name, $cid);
        }

        return null;
    }

    /** Create a postable ledger account, guessing its type from the code's leading digit. */
    private function createGlAccount(string $code, string $name, ?int $cid): int
    {
        [$type, $normal] = $this->accountTypeForCode($code);
        try {
            $account = FinAccount::create([
                'company_id'     => $cid,
                'code'           => $code,
                'name'           => $name !== '' ? $name : ('Imported account ' . $code),
                'type'           => $type,
                'normal_balance' => $normal,
                'is_postable'    => true,
                'is_active'      => true,
            ]);

            return (int) $account->id;
        } catch (\Throwable) {
            // Code collides with a non-postable header (or similar) — fall back to suspense.
            return $this->suspenseAccountId();
        }
    }

    /** SA/generic chart-of-accounts numbering heuristic: 1x asset, 2x liability, 3x equity, 4-5x income, else expense. */
    private function accountTypeForCode(string $code): array
    {
        return match (ltrim($code)[0] ?? '6') {
            '1'      => ['asset', 'debit'],
            '2'      => ['liability', 'credit'],
            '3'      => ['equity', 'credit'],
            '4', '5' => ['income', 'credit'],
            default  => ['expense', 'debit'],
        };
    }

    /**
     * Import customer statements as OPENING BALANCES: each row's outstanding amount becomes an
     * opening-balance invoice for that customer (so their account and the AR ageing are correct),
     * with the contra posted to opening-balance equity rather than income — the right treatment
     * for a migration, and distinct from the full invoice import. Credit balances come in as
     * opening credit notes. Re-imports are skipped on the source reference.
     */
    private function importStatements(array $rows, array $map): array
    {
        if (! isset($map['contact'])) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => [['row' => 1, 'reason' => 'Could not find a customer column to attach opening balances to.']]];
        }
        $obAccount = $this->openingBalanceAccountId();

        $imported = 0;
        $skipped = [];
        $seq = 0;
        foreach ($rows as $row) {
            $contact = trim((string) ($row[$map['contact']] ?? ''));
            if ($contact === '') {
                continue; // statement header / blank line
            }
            $amount = $this->documentTotal($row, $map);
            if ($amount === null || abs($amount) < 0.01) {
                continue; // zero-balance customer — nothing to open
            }
            $num = $this->s(isset($map['number']) ? ($row[$map['number']] ?? null) : null) ?? ('OB-' . strtoupper(substr(md5($contact), 0, 6)) . '-' . (++$seq));
            if (FinInvoice::where('party_ref', $num)->exists()) {
                $skipped[] = ['row' => $num, 'reason' => 'Already imported.'];
                continue;
            }

            $party = $this->partyByName($contact, 'customer');
            $date = $this->parseDate(isset($map['date']) ? ($row[$map['date']] ?? null) : null);
            $this->ensurePeriodFor($date);
            $line = [[
                'account_id'  => $obAccount,
                'description' => 'Opening balance',
                'quantity'    => 1,
                'unit_price'  => abs($amount),
                'tax_rate'    => 0,
            ]];

            try {
                if ($amount > 0) {
                    $this->arap->createInvoice([
                        'party_id'      => $party->id,
                        'direction'     => 'receivable',
                        'invoice_date'  => $date,
                        'due_date'      => $this->parseDate(isset($map['due_date']) ? ($row[$map['due_date']] ?? null) : null),
                        'party_ref'     => $num,
                        'lines'         => $line,
                        'relieve_stock' => false,
                    ]);
                } else {
                    // A credit balance — the customer is in credit; bring it in as an opening credit note.
                    $this->arap->importCreditNote([
                        'party_id'    => $party->id,
                        'credit_date' => $date,
                        'party_ref'   => $num,
                        'description' => 'Opening balance (credit)',
                        'lines'       => $line,
                    ]);
                }
                $imported++;
            } catch (\Throwable $e) {
                Log::warning('External import: statement failed', ['ref' => $num, 'error' => $e->getMessage()]);
                $skipped[] = ['row' => $num, 'reason' => $e->getMessage()];
            }
        }

        return ['imported' => $imported, 'updated' => 0, 'skipped' => $skipped];
    }

    private ?int $openingBalanceId = null;

    /** The opening-balance equity account statements post their contra to (created on demand). */
    private function openingBalanceAccountId(): int
    {
        if ($this->openingBalanceId !== null) {
            return $this->openingBalanceId;
        }
        $cid = $this->currentCompany->id() ?? auth()->user()?->company_id;
        $code = (string) config('finance.opening_balance', config('finance.retained_earnings_account', '3100'));
        $account = FinAccount::firstOrCreate(
            ['company_id' => $cid, 'code' => $code],
            ['name' => 'Opening Balances', 'type' => 'equity', 'normal_balance' => 'credit', 'is_postable' => true, 'is_active' => true],
        );
        if (! $account->is_postable || ! $account->is_active) {
            $account->update(['is_postable' => true, 'is_active' => true]);
        }

        return $this->openingBalanceId = (int) $account->id;
    }

    private ?int $suspenseId = null;

    /** The migration suspense account for balancing one-sided/rounding differences (created on demand). */
    private function suspenseAccountId(): int
    {
        if ($this->suspenseId !== null) {
            return $this->suspenseId;
        }
        $cid = $this->currentCompany->id() ?? auth()->user()?->company_id;
        $code = (string) config('finance.import_suspense', '9999');
        $account = FinAccount::firstOrCreate(
            ['company_id' => $cid, 'code' => $code],
            ['name' => 'Migration Suspense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true],
        );
        if (! $account->is_postable || ! $account->is_active) {
            $account->update(['is_postable' => true, 'is_active' => true]);
        }

        return $this->suspenseId = (int) $account->id;
    }

    /**
     * Full re-post: group line rows by document number and recreate each invoice/bill/quote
     * through the same posting services as manual entry, so every GL journal is correct and
     * balanced. Re-imports are skipped (matched on the source number kept in party_ref).
     */
    private function importDocuments(array $rows, array $map, string $entity): array
    {
        if (! isset($map['number'], $map['contact'])) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => [['row' => 1, 'reason' => 'Could not find a document number and contact column to group by.']]];
        }

        $direction = $entity === 'bills' ? 'payable' : 'receivable';
        $kind = $entity === 'bills' ? 'supplier' : 'customer';

        // Group rows by document number, preserving order.
        $docs = [];
        foreach ($rows as $r) {
            $num = trim((string) ($r[$map['number']] ?? ''));
            if ($num === '') {
                continue;
            }
            $docs[$num][] = $r;
        }

        $imported = 0;
        $skipped = [];
        foreach ($docs as $num => $group) {
            $head = $group[0];
            $contact = trim((string) ($head[$map['contact']] ?? ''));
            if ($contact === '') {
                $skipped[] = ['row' => $num, 'reason' => 'Missing contact name.'];
                continue;
            }

            // Skip if we already re-posted this source document for this tenant.
            if ($entity !== 'quotes' && FinInvoice::where('party_ref', $num)->exists()) {
                $skipped[] = ['row' => $num, 'reason' => 'Already imported.'];
                continue;
            }

            $party = $this->partyByName($contact, $kind);
            $lines = [];
            foreach ($group as $r) {
                $qty = $this->num(isset($map['quantity']) ? ($r[$map['quantity']] ?? null) : null);
                $price = $this->num(isset($map['unit_price']) ? ($r[$map['unit_price']] ?? null) : null);
                // A row with no quantity AND no price is not a real line (e.g. a summary export
                // that has only a document total) — skip it; the total fallback below handles it.
                if (($qty ?? 0) == 0 && ($price ?? 0) == 0) {
                    continue;
                }
                $lines[] = [
                    'account_id'  => $this->resolveAccount($map, $r, $direction),
                    'description' => $this->s(isset($map['description']) ? ($r[$map['description']] ?? null) : null) ?? $num,
                    'quantity'    => $qty ?? 1,
                    'unit_price'  => $price ?? 0,
                    'tax_rate'    => $this->num(isset($map['tax_rate']) ? ($r[$map['tax_rate']] ?? null) : null) ?? 0,
                ];
            }

            // Summary export (no line-level pricing) → post a single line at the document total.
            $creditNote = false;
            if ($lines === []) {
                $total = $this->documentTotal($head, $map);
                if ($total !== null && abs($total) > 0) {
                    // A negative total — or a credit-note reference (CRN…/CN…) — is a credit
                    // note, not an invoice. Bring it in as a proper (unapplied) credit note.
                    $ref = strtoupper($num);
                    $looksCredit = $total < 0 || str_starts_with($ref, 'CRN') || str_starts_with($ref, 'CN');
                    if ($looksCredit && $entity !== 'quotes') {
                        if ($direction !== 'receivable') {
                            $skipped[] = ['row' => $num, 'reason' => 'Supplier credit note — import separately.'];
                            continue;
                        }
                        $creditNote = true;
                    }
                    // Prefer the export's own exclusive/VAT split, so the journal separates
                    // revenue from VAT control instead of booking the gross as revenue.
                    $net = $this->num(isset($map['net']) ? ($head[$map['net']] ?? null) : null);
                    $tax = $this->num(isset($map['tax_amount']) ? ($head[$map['tax_amount']] ?? null) : null);
                    $hasSplit = $net !== null && abs($net) > 0 && $tax !== null;

                    $lines = [[
                        'account_id'  => $this->resolveAccount($map, $head, $direction),
                        'description' => ($creditNote ? 'Imported credit note ' : 'Imported ' . ($entity === 'bills' ? 'bill ' : ($entity === 'quotes' ? 'quote ' : 'invoice '))) . $num,
                        'quantity'    => 1,
                        // Credit notes are carried as a positive magnitude.
                        'unit_price'  => $hasSplit ? abs($net) : abs($total),
                        'tax_rate'    => $hasSplit ? round(abs($tax) / abs($net) * 100, 2) : 0,
                    ]];
                }
            }

            if ($lines === []) {
                $skipped[] = ['row' => $num, 'reason' => 'No line items or amount found in the file.'];
                continue;
            }

            $docDate = $this->parseDate(isset($map['date']) ? ($head[$map['date']] ?? null) : null);
            // Historical migration: open the fiscal year/period covering this date so it posts.
            $this->ensurePeriodFor($docDate);

            try {
                if ($entity === 'quotes') {
                    $this->quotes->createQuote([
                        'party_id' => $party->id,
                        'quote_date' => $docDate,
                        'valid_until' => $this->parseDate(isset($map['valid_until']) ? ($head[$map['valid_until']] ?? null) : null),
                        'customer_ref' => $num,
                        'lines' => $lines,
                    ]);
                } elseif ($creditNote) {
                    $this->arap->importCreditNote([
                        'party_id' => $party->id,
                        'credit_date' => $docDate,
                        'party_ref' => $num,
                        'description' => 'Imported credit note ' . $num,
                        'lines' => $lines,
                    ]);
                } else {
                    $this->arap->createInvoice([
                        'party_id' => $party->id,
                        'direction' => $direction,
                        'invoice_date' => $docDate,
                        'due_date' => $this->parseDate(isset($map['due_date']) ? ($head[$map['due_date']] ?? null) : null),
                        'party_ref' => $num,
                        'lines' => $lines,
                        'relieve_stock' => false,
                    ]);
                }
                $imported++;
            } catch (\Throwable $e) {
                Log::warning('External import: document failed', ['number' => $num, 'error' => $e->getMessage()]);
                $skipped[] = ['row' => $num, 'reason' => $e->getMessage()];
            }
        }

        return ['imported' => $imported, 'updated' => 0, 'skipped' => $skipped];
    }

    /**
     * The document total. Prefer the mapped 'total' column; if it's blank/zero (or unmapped —
     * common with Sage reports whose amount column has an unrecognised header), fall back to
     * the largest-magnitude numeric value in the row, ignoring the id/date/reference columns.
     */
    private function documentTotal(array $head, array $map): ?float
    {
        $mapped = $this->num(isset($map['total']) ? ($head[$map['total']] ?? null) : null);
        if ($mapped !== null && abs($mapped) > 0) {
            return $mapped;
        }

        // Columns that are NOT amounts — never treat these as the total.
        $skipHeaders = [];
        foreach (['number', 'contact', 'date', 'due_date', 'valid_until', 'account_code', 'item_code', 'quantity', 'tax_rate', 'tax_amount'] as $f) {
            if (isset($map[$f])) {
                $skipHeaders[$map[$f]] = true;
            }
        }
        // …and columns that are amounts but not THIS one: what's still owed, ageing buckets,
        // discounts. Picking the biggest number in the row would otherwise land on one of these.
        $notTheTotal = ['outstanding', 'totaloutstanding', 'balance', 'balancedue', 'amountdue', 'amountoutstanding',
            'paid', 'amountpaid', 'antpmt', 'anticipatedpayment', 'discount', 'daysoverdue', 'days', 'age'];

        $best = null;
        foreach ($head as $header => $value) {
            if (isset($skipHeaders[$header]) || in_array($this->norm((string) $header), $notTheTotal, true)) {
                continue;
            }
            $n = $this->num($value);
            if ($n !== null && ($best === null || abs($n) > abs($best))) {
                $best = $n;
            }
        }

        return $best;
    }

    /** Find a party by name for the given kind, creating it if absent. */
    private function partyByName(string $name, string $kind): FinParty
    {
        $party = FinParty::query()->whereIn('kind', [$kind, 'both'])->where('name', $name)->first();

        return $party ?: FinParty::create(['kind' => $kind, 'name' => $name, 'source' => 'imported', 'is_active' => true]);
    }

    /** Resolve a line's ledger account: source code → account, else a sensible default. */
    private function resolveAccount(array $map, array $row, string $direction): int
    {
        // Scope explicitly to the current company — the ledger resolves the same account by
        // (id AND company_id), so a cross-company match here would fail at posting time.
        $cid = $this->currentCompany->id();
        $scoped = fn ($q) => $cid ? $q->where('company_id', $cid) : $q;

        if (isset($map['account_code'])) {
            $code = trim((string) ($row[$map['account_code']] ?? ''));
            // Only accept the coded account if it is actually postable — a header/summary
            // account (e.g. a "4000 Sales" parent) can't take journal lines.
            if ($code !== '' && ($id = $scoped(FinAccount::where('code', $code)->where('is_postable', true)->where('is_active', true))->value('id'))) {
                return (int) $id;
            }
        }

        // Default: the first POSTABLE income account for sales, expense for purchases.
        // Ordering by code alone would pick a non-postable header account first.
        $type = $direction === 'payable' ? 'expense' : 'income';
        $this->defaultAccount[$type] ??= (int) ($scoped(FinAccount::where('type', $type)->where('is_postable', true)->where('is_active', true))->orderBy('code')->value('id') ?? 0);

        if ($this->defaultAccount[$type] === 0) {
            throw new \RuntimeException("No postable {$type} account in your chart of accounts to post lines to. Add one first.");
        }

        return $this->defaultAccount[$type];
    }

    private array $defaultAccount = [];

    /**
     * Ensure an (open) fiscal year — and thus the monthly period — exists covering $date, so a
     * historical migration document posts to its true period instead of being skipped. Standard
     * for accounting migrations: the periods land open and the user can close them afterwards.
     * Idempotent and cached per start-year; createYear() returns the existing year if present.
     */
    private function ensurePeriodFor(?string $date): void
    {
        if ($date === null) {
            return;
        }
        try {
            $d = \Illuminate\Support\Carbon::parse($date);
        } catch (\Throwable) {
            return;
        }
        $companyId = $this->currentCompany->id();
        $startYear = $this->fiscalYears->startYearForDate($d, $this->fiscalYears->startMonth($companyId));
        $key = $companyId . ':' . $startYear;
        if (isset($this->ensuredYears[$key])) {
            return;
        }
        $this->fiscalYears->createYear($companyId, $startYear);
        $this->ensuredYears[$key] = true;
    }

    private array $ensuredYears = [];

    /**
     * The chart of accounts out of the old system's account list. Sage's export identifies an
     * account by NAME and groups it by category rather than typing or numbering it, so the type
     * is derived from the category (kept as the subtype) and a code is generated in that type's
     * band when the file has none. Matched on code when present, else on name, so a re-run
     * updates the account instead of creating a second one under a new number.
     *
     * Opening balances are deliberately NOT posted here: an account list is master data, and its
     * balances rarely add up to a balanced trial balance on their own. Bring balances in through
     * the trial balance (Transactions) or customer statements instead.
     */
    private function importAccounts(array $rows, array $map): array
    {
        $companyId = $this->currentCompany->id() ?? auth()->user()?->company_id;
        $imported = $updated = 0;
        $skipped = [];

        foreach ($rows as $i => $row) {
            $data = $this->mapRow($row, $map);
            $name = trim((string) ($data['name'] ?? ''));
            $code = trim((string) ($data['code'] ?? ''));
            if ($name === '' && $code === '') {
                continue;
            }
            if ($name === '') {
                $skipped[] = ['row' => $i + 2, 'reason' => 'Missing account name.'];
                continue;
            }

            $category = $this->s($data['category'] ?? null);
            $type = $this->accountTypeForCategory($category, $code);
            if ($type === null) {
                $skipped[] = ['row' => $i + 2, 'reason' => "Could not tell what kind of account \"{$name}\" is" . ($category ? " from category \"{$category}\"." : ' — no category column.')];
                continue;
            }

            $existing = FinAccount::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($code !== '', fn ($q) => $q->where('code', $code), fn ($q) => $q->whereRaw('lower(name) = ?', [strtolower($name)]))
                ->first();

            $attrs = array_filter([
                'name'           => $name,
                'type'           => $type,
                'normal_balance' => FinAccount::NORMAL_BALANCE[$type],
                'subtype'        => $category,                         // keeps "Cost of Sales" apart from "Expenses"
                'tax_code'       => $this->s($data['tax_code'] ?? null),
                'is_active'      => $this->bool($data['is_active'] ?? null),
            ], fn ($v) => $v !== null);

            try {
                if ($existing) {
                    $existing->fill($attrs)->save();
                    $updated++;
                } else {
                    FinAccount::create($attrs + [
                        'company_id'  => $companyId,
                        'code'        => $code !== '' ? $code : $this->generateAccountCode($type, $companyId),
                        'is_postable' => true,     // an imported account is a leaf you can post to
                        'is_active'   => true,
                    ]);
                    $imported++;
                }
            } catch (\Throwable $e) {
                Log::warning('External import: account failed', ['name' => $name, 'error' => $e->getMessage()]);
                $skipped[] = ['row' => $i + 2, 'reason' => $e->getMessage()];
            }
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** Ledger type from the source system's category label, falling back to the code's leading digit. */
    private function accountTypeForCategory(?string $category, string $code): ?string
    {
        $key = $this->norm((string) $category);
        if ($key !== '' && isset(self::CATEGORY_TYPES[$key])) {
            return self::CATEGORY_TYPES[$key];
        }
        // An unknown label still usually says what it is ("Other Expenses", "Trade Receivables").
        foreach (self::CATEGORY_TYPES as $needle => $type) {
            if ($key !== '' && str_contains($key, $needle)) {
                return $type;
            }
        }

        return $code !== '' ? $this->accountTypeForCode($code)[0] : null;
    }

    /** @var array<string,int> next free number per type, and the codes already taken */
    private array $nextCode = [];
    private ?array $usedCodes = null;

    private function generateAccountCode(string $type, ?int $companyId): string
    {
        if ($this->usedCodes === null) {
            /*
             * The shared chart counts as taken. It is seeded with a null company and every tenant
             * posts against it, so a code generated only against this company's own rows could be
             * handed out for something the shared chart already uses — two different accounts
             * answering to one code, and the ledger resolving whichever it found first.
             */
            $this->usedCodes = array_flip(
                FinAccount::query()
                    ->where(fn ($q) => $q->whereNull('company_id')
                        ->when($companyId, fn ($inner) => $inner->orWhere('company_id', $companyId)))
                    ->pluck('code')
                    ->all()
            );
        }
        $base = self::CODE_BANDS[$type] ?? 6000;
        $this->nextCode[$type] ??= $base;

        while (isset($this->usedCodes[(string) $this->nextCode[$type]])) {
            $this->nextCode[$type]++;
        }
        $code = (string) $this->nextCode[$type]++;
        $this->usedCodes[$code] = true;

        return $code;
    }

    /**
     * Master-data upsert for customers/suppliers. Also returns the ids it touched, so the caller
     * can hand exactly this batch to the CRM / Supplier Management sync.
     */
    private function importParties(array $rows, array $map, string $kind): array
    {
        $imported = $updated = 0;
        $skipped = [];
        $partyIds = [];
        foreach ($rows as $i => $row) {
            $data = $this->mapRow($row, $map);
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                if (array_filter($data)) {
                    $skipped[] = ['row' => $i + 2, 'reason' => 'Missing name.'];
                }
                continue;
            }
            $email = trim((string) ($data['email'] ?? ''));
            // Match on email (if present) else name, so re-imports update instead of duplicate.
            $existing = FinParty::query()
                ->when($email !== '', fn ($q) => $q->whereRaw('lower(email) = ?', [strtolower($email)]), fn ($q) => $q->where('name', $name))
                ->whereIn('kind', [$kind, 'both'])->first();

            $attrs = array_filter([
                'name' => $name, 'contact_name' => $this->s($data['contact_name'] ?? null),
                'email' => $email ?: null, 'website' => $this->s($data['website'] ?? null),
                'phone' => $this->s($data['phone'] ?? null), 'fax' => $this->s($data['fax'] ?? null),
                'vat_number' => $this->s($data['vat_number'] ?? null), 'registration_number' => $this->s($data['registration_number'] ?? null),
                'address' => $this->s($data['address'] ?? null), 'postal_address' => $this->s($data['postal_address'] ?? null),
                'city' => $this->s($data['city'] ?? null),
                'country' => $this->s($data['country'] ?? null), 'code' => $this->s($data['code'] ?? null),
                'category' => $this->s($data['category'] ?? null), 'sales_rep' => $this->s($data['sales_rep'] ?? null),
                'credit_limit' => $this->num($data['credit_limit'] ?? null),
                'credit_terms_days' => $this->int($data['credit_terms_days'] ?? null),
                'default_discount_percent' => $this->num($data['default_discount_percent'] ?? null),
                'is_active' => $this->bool($data['is_active'] ?? null),
                'price_list_id' => $this->priceListId($this->s($data['price_list'] ?? null)),
            ], fn ($v) => $v !== null);

            if ($existing) {
                $existing->fill($attrs)->save();
                $partyIds[] = (int) $existing->id;
                $updated++;
            } else {
                // is_active from the file (if the file said) wins over the default below.
                $party = FinParty::create($attrs + ['kind' => $kind, 'source' => 'imported', 'is_active' => true]);
                $partyIds[] = (int) $party->id;
                $imported++;
            }
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'party_ids' => $partyIds];
    }

    private function importItems(array $rows, array $map): array
    {
        $imported = $updated = 0;
        $skipped = [];
        foreach ($rows as $i => $row) {
            $data = $this->mapRow($row, $map);
            $name = trim((string) ($data['name'] ?? ''));
            $code = trim((string) ($data['code'] ?? ''));
            if ($name === '' && $code === '') {
                continue;
            }
            if ($name === '') {
                $skipped[] = ['row' => $i + 2, 'reason' => 'Missing item name.'];
                continue;
            }
            $existing = $code !== '' ? FinItem::where('code', $code)->first() : FinItem::where('name', $name)->first();
            $attrs = array_filter([
                'name' => $name, 'code' => $code ?: null, 'description' => $this->s($data['description'] ?? null),
                'unit' => $this->s($data['unit'] ?? null), 'sales_price' => $this->num($data['sales_price'] ?? null),
                'cost_price' => $this->num($data['cost_price'] ?? null), 'tax_rate' => $this->num($data['tax_rate'] ?? null),
            ], fn ($v) => $v !== null);

            if ($existing) {
                $existing->fill($attrs)->save();
                $updated++;
            } else {
                FinItem::create($attrs + ['type' => 'product', 'is_active' => true]);
                $imported++;
            }
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    private array $priceLists = [];

    /** An existing price list with this name, or null — an import never creates pricing structures. */
    private function priceListId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }
        $key = strtolower($name);
        if (! array_key_exists($key, $this->priceLists)) {
            $id = FinPriceList::whereRaw('lower(name) = ?', [$key])->value('id');
            $this->priceLists[$key] = $id ? (int) $id : null;
        }

        return $this->priceLists[$key];
    }

    /** field => source header(s), for headers present in the file. */
    private function buildMap(array $headers, string $system, string $entity): array
    {
        $system = in_array($system, self::SYSTEMS, true) ? $system : 'generic';
        $profile = $this->profiles()[$entity] ?? [];
        $normHeaders = [];
        foreach ($headers as $h) {
            $normHeaders[$this->norm($h)] = $h;
        }

        $map = [];
        foreach ($profile as $field => $bySystem) {
            // Try the chosen system's candidates first, then the generic ones.
            $candidates = array_merge($bySystem[$system] ?? [], $bySystem['generic'] ?? []);
            foreach ($candidates as $cand) {
                if (isset($normHeaders[$this->norm($cand)])) {
                    $map[$field] = $normHeaders[$this->norm($cand)];
                    break;
                }
            }
        }

        // Multi-column fields. These take over from the single-column guess only when the file
        // really does split the value over several columns; a lone hit leaves the guess alone.
        foreach (($this->groupProfiles()[$entity] ?? []) as $field => $bySystem) {
            $cols = [];
            foreach (array_merge($bySystem[$system] ?? [], $bySystem['generic'] ?? []) as $cand) {
                $header = $normHeaders[$this->norm($cand)] ?? null;
                if ($header !== null && ! in_array($header, $cols, true)) {
                    $cols[] = $header;
                }
            }
            if (count($cols) > 1) {
                $map[$field] = $cols;
            } elseif (count($cols) === 1 && ! isset($map[$field])) {
                $map[$field] = $cols[0];
            }
        }

        return $map;
    }

    private function mapRow(array $row, array $map): array
    {
        $out = [];
        foreach ($map as $field => $header) {
            if (! is_array($header)) {
                $out[$field] = $row[$header] ?? null;
                continue;
            }
            // Grouped field: join the columns the row actually filled in, in file order.
            $parts = [];
            foreach ($header as $h) {
                $v = trim((string) ($row[$h] ?? ''));
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            $out[$field] = $parts === [] ? null : implode(', ', $parts);
        }

        return $out;
    }

    /** @return array{0: array<string>, 1: array<int, array<string,mixed>>} */
    private function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        $path = $file->getRealPath();

        // CSV / text: parse natively. IOFactory auto-detection on an extension-less temp file
        // mis-reads plain text as HTML (the "Failed to load … as a DOM Document" crash).
        if (in_array($ext, ['csv', 'txt', ''], true)) {
            return $this->matrixToRows($this->readCsvMatrix($path));
        }

        try {
            // Pick the reader from the real extension rather than letting IOFactory guess.
            $reader = IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
            $reader->setReadDataOnly(true);
            $matrix = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable) {
            // Some "Excel" exports are really CSV, or an HTML table saved as .xls — fall back.
            $matrix = $this->readCsvMatrix($path);
        }

        return $this->matrixToRows($matrix);
    }

    /** Read a delimited text file into a matrix, auto-detecting comma vs semicolon vs tab. */
    private function readCsvMatrix(string $path): array
    {
        $matrix = [];
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return $matrix;
        }
        $first = (string) fgets($handle);
        rewind($handle);
        $delim = ',';
        foreach ([';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")] as $d => $count) {
            if ($count > substr_count($first, ',')) {
                $delim = $d;
            }
        }
        // Escape passed explicitly — PHP 8.4 deprecates relying on its default, and every
        // accounting import was writing a deprecation notice per row because of it.
        while (($row = fgetcsv($handle, 0, $delim, '"', '\\')) !== false) {
            $matrix[] = $row;
        }
        fclose($handle);

        return $matrix;
    }

    /** Turn a matrix into [headers, assoc-rows]. Skips leading title/blank rows (Sage reports). */
    private function matrixToRows(array $matrix): array
    {
        // The header row is the first row with 2+ non-empty cells — this drops report titles,
        // date-range lines and blank rows that accounting-system exports put above the table.
        $headerIdx = null;
        foreach ($matrix as $i => $row) {
            $nonEmpty = count(array_filter($row, fn ($c) => trim((string) $c) !== ''));
            if ($nonEmpty >= 2) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            return [[], []];
        }

        $rawHeaders = array_map(fn ($h) => trim((string) $h), $matrix[$headerIdx]);
        // Name any blank header columns so their data is still addressable (e.g. "col_3").
        $headers = [];
        foreach ($rawHeaders as $idx => $h) {
            $headers[$idx] = $h !== '' ? $h : 'col_' . ($idx + 1);
        }

        $rows = [];
        foreach (array_slice($matrix, $headerIdx + 1, null, true) as $row) {
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // skip blank rows
            }
            $assoc = [];
            foreach ($headers as $idx => $key) {
                $assoc[$key] = $row[$idx] ?? null;
            }
            $rows[] = $assoc;
        }

        return [array_values($headers), $rows];
    }

    /**
     * Normalise a header/candidate to pure alphanumeric so "Contact Name", "ContactName" and
     * "contact_name" all collapse to "contactname" — the different systems mix these styles.
     */
    private function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }

    private function s(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : $v;
    }

    private function num(mixed $v): ?float
    {
        $v = preg_replace('/[^0-9.\-]/', '', (string) ($v ?? ''));

        return $v === '' ? null : (float) $v;
    }

    private function int(mixed $v): ?int
    {
        $n = $this->num($v);

        return $n === null ? null : (int) round($n);
    }

    /**
     * A yes/no column. Exports write these as True/False (Sage), Yes/No or 1/0; anything we
     * don't recognise returns null so the field is left at its default rather than guessed.
     */
    private function bool(mixed $v): ?bool
    {
        $v = strtolower(trim((string) ($v ?? '')));
        if ($v === '') {
            return null;
        }

        return match (true) {
            in_array($v, ['true', 'yes', 'y', '1', 'active', 'enabled'], true)    => true,
            in_array($v, ['false', 'no', 'n', '0', 'inactive', 'disabled'], true) => false,
            default                                                               => null,
        };
    }

    /**
     * Parse a date from an accounting export to Y-m-d. South African exports are day-first
     * (22/06/2023, 4/7/23) which Carbon mis-reads, so try day-first formats before falling
     * back to Carbon; also handles Excel serial numbers.
     */
    private function parseDate(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '') {
            return null;
        }

        // Excel date serial (when read from a spreadsheet cell as a number).
        if (is_numeric($v) && (float) $v > 30000 && (float) $v < 80000) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
            } catch (\Throwable) {
                // fall through
            }
        }

        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $v);
            $err = \DateTime::getLastErrors();
            if ($dt !== false && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                // Reject a 4-digit-year format that leniently swallowed a 2-digit year
                // (e.g. "4/7/23" under d/m/Y → year 0023) so the d/m/y pass handles it.
                $year = (int) $dt->format('Y');
                if ($year >= 1970 && $year <= 2100) {
                    return $dt->format('Y-m-d');
                }
            }
        }

        try {
            return \Carbon\Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
