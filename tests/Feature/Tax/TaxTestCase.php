<?php

namespace Tests\Feature\Tax;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinInvoiceLine;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxRuleVersion;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Finance\Tax\Services\TaxRegistrationService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fixtures for the tax acceptance matrix (SRS §25): a tenant with one business, VAT control
 * accounts, a preparer, a reviewer/approver and an outsider, and helpers that post real Finance
 * documents (invoice + journal) so the register is built the way it is in production.
 */
abstract class TaxTestCase extends TestCase
{
    use DatabaseTransactions;

    protected Company $company;

    protected FinEntity $entity;

    protected User $preparer;

    protected User $reviewer;

    protected User $outsider;

    /** @var array<string, FinAccount> */
    protected array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (TaxPermissions::all() as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $this->company = Company::create(['name' => 'NSEZ Test ' . Str::random(5)]);
        DB::table('company_modules')->updateOrInsert(['company_id' => $this->company->id, 'module_code' => 'tax_management'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('module_active_tax_management');
        $this->enterTenant();
        $this->entity = app(CurrentEntity::class)->defaultFor($this->company->id);
        app(CurrentEntity::class)->set($this->entity->id);

        $this->preparer = $this->user('preparer', [TaxPermissions::VIEW, TaxPermissions::PREPARE, TaxPermissions::RECONCILE, TaxPermissions::DRAFT_RULES]);
        $this->reviewer = $this->user('reviewer', [TaxPermissions::VIEW, TaxPermissions::REVIEW, TaxPermissions::APPROVE, TaxPermissions::SIGN_OFF,
            TaxPermissions::APPROVE_RULES, TaxPermissions::REOPEN_PERIODS, TaxPermissions::MANAGE_PERIODS, TaxPermissions::MANAGE_REGISTRATIONS,
            TaxPermissions::VIEW_AUDIT, TaxPermissions::MANAGE_CALENDAR, TaxPermissions::RECONCILE]);
        $this->outsider = $this->user('outsider', [TaxPermissions::VIEW]);

        foreach (['vat_out' => ['2200', 'liability'], 'vat_in' => ['1400', 'asset'], 'sales' => ['4000', 'income'],
            'purchases' => ['5000', 'expense'], 'debtors' => ['1100', 'asset'], 'creditors' => ['2100', 'liability']] as $key => [$code, $type]) {
            $this->accounts[$key] = FinAccount::create(['company_id' => $this->company->id, 'code' => $code,
                'name' => "Tax fixture {$key}", 'type' => $type, 'is_postable' => true, 'is_active' => true]);
        }
        config(['finance.vat_output' => '2200', 'finance.vat_input' => '1400']);
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    protected function enterTenant(?Company $company = null): void
    {
        $company ??= $this->company;
        app(CurrentCompany::class)->set($company->id);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $company->id]);
    }

    protected function user(string $name, array $permissions): User
    {
        $user = User::factory()->create(['email' => "{$name}-" . Str::random(6) . '@tax.test', 'status' => 1, 'company_id' => $this->company->id]);
        $user->givePermissionTo($permissions);
        $moduleId = DB::table('security_modules')->where('code_name', 'tax_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(['module_id' => $moduleId, 'user_id' => $user->id],
            ['active' => 1, 'access_level' => 5, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('hub_module_id:tax_management');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function vat(): TaxType
    {
        return TaxType::byCode('VAT');
    }

    /** A published rule, created directly (the approval path itself is tested in TaxRuleLifecycleTest). */
    protected function publishedRule(string $code, string $rate, string $treatment, string $direction, string $from = '2018-04-01', ?string $field = null, ?string $to = null): TaxRuleVersion
    {
        return TaxRuleVersion::create([
            'tax_type_id' => $this->vat()->id, 'tax_code' => $code, 'name' => $code, 'version' => (int) TaxRuleVersion::where('tax_code', $code)->max('version') + 1,
            'treatment' => $treatment, 'direction' => $direction, 'method' => 'percentage', 'rate' => $rate, 'rounding' => 'half_up', 'precision' => 2,
            'return_field' => $field, 'effective_from' => $from, 'effective_to' => $to, 'status' => 'published', 'published_at' => now(),
        ]);
    }

    protected function standardRules(): void
    {
        $this->publishedRule('STD', '15', 'standard', 'output', field: '1');
        $this->publishedRule('STDI', '15', 'standard', 'input', field: '15');
        $this->publishedRule('ZR', '0', 'zero_rated', 'output', field: '2');
        $this->publishedRule('EX', '0', 'exempt', 'output', field: '3');
    }

    /** A confirmed VAT registration (bi-monthly, Category B) with its periods generated. */
    protected function vatRegistration(string $from = '2026-01-01'): TaxRegistration
    {
        $this->actingAs($this->reviewer);
        $registrations = app(TaxRegistrationService::class);
        $reg = $registrations->create([
            'entity_id' => $this->entity->id, 'tax_type_id' => $this->vat()->id, 'reference' => '4123456789',
            'effective_from' => $from, 'filing_frequency' => 'bi_monthly_even',
            'preparer_id' => $this->preparer->id, 'reviewer_id' => $this->reviewer->id,
        ]);
        $registrations->confirmApplicability($reg, 'SARS registration letter on file.');
        // Confirmed when the business registered, as it would have been in practice: deadlines that
        // passed before confirmation are treated as history (waived), and these tests exercise live ones.
        $reg->forceFill(['confirmed_at' => $from])->save();
        app(TaxPeriodService::class)->generate($reg->fresh(), now()->parse('2026-12-31'));

        return $reg->fresh();
    }

    protected function period(string $containing): TaxPeriod
    {
        return TaxPeriod::query()->whereDate('start_date', '<=', $containing)->whereDate('end_date', '>=', $containing)->firstOrFail();
    }

    /**
     * A posted invoice or credit note, with its journal on the VAT control account.
     *
     * @param  list<array{0:string,1:string,2:?string}>  $lines  [net, rate %, tax code]
     */
    protected function invoice(string $direction, string $date, array $lines, string $docType = 'invoice', ?string $partyVat = '4999999999', string $currency = 'ZAR', ?string $fx = null, ?bool $postVat = true): FinInvoice
    {
        $party = FinParty::create(['company_id' => $this->company->id, 'kind' => $direction === 'receivable' ? 'customer' : 'supplier',
            'code' => 'P' . Str::random(6), 'name' => 'Party ' . Str::random(4), 'vat_number' => $partyVat]);

        $net = '0';
        $tax = '0';
        foreach ($lines as [$n, $r]) {
            $net = bcadd($net, $n, 2);
            $tax = bcadd($tax, bcdiv(bcmul($n, $r, 4), '100', 2), 2);
        }

        $journal = FinJournal::create(['company_id' => $this->company->id, 'journal_no' => 'J-' . Str::random(8), 'journal_date' => $date,
            'source_module' => 'finance_ar', 'currency' => $currency, 'status' => 'posted']);
        $rate = $fx ?? '1';
        $sign = $docType === 'credit_note' ? -1 : 1;
        $post = function (FinAccount $account, string $amount, bool $debit) use ($journal, $rate, $currency, $sign) {
            $base = bcmul($amount, $rate, 2);
            $isDebit = $sign > 0 ? $debit : ! $debit;
            FinJournalLine::create(['journal_id' => $journal->id, 'account_id' => $account->id, 'company_id' => $this->company->id,
                'currency' => $currency, 'fx_rate' => $rate,
                'debit' => $isDebit ? $amount : 0, 'credit' => $isDebit ? 0 : $amount,
                'base_debit' => $isDebit ? $base : 0, 'base_credit' => $isDebit ? 0 : $base]);
        };
        if ($direction === 'receivable') {
            $post($this->accounts['debtors'], bcadd($net, $tax, 2), true);
            $post($this->accounts['sales'], $net, false);
            if ($postVat && bccomp($tax, '0', 2) !== 0) {
                $post($this->accounts['vat_out'], $tax, false);
            }
        } else {
            $post($this->accounts['purchases'], $net, true);
            if ($postVat && bccomp($tax, '0', 2) !== 0) {
                $post($this->accounts['vat_in'], $tax, true);
            }
            $post($this->accounts['creditors'], bcadd($net, $tax, 2), false);
        }

        $invoice = FinInvoice::create(['company_id' => $this->company->id, 'party_id' => $party->id, 'direction' => $direction, 'doc_type' => $docType,
            'number' => strtoupper(substr($direction, 0, 2)) . '-' . Str::random(6), 'invoice_date' => $date, 'due_date' => $date,
            'currency' => $currency, 'status' => 'open', 'subtotal' => $net, 'tax_total' => $tax, 'total' => bcadd($net, $tax, 2),
            'journal_id' => $journal->id]);

        foreach ($lines as [$n, $r, $code]) {
            FinInvoiceLine::create(['invoice_id' => $invoice->id, 'account_id' => $direction === 'receivable' ? $this->accounts['sales']->id : $this->accounts['purchases']->id,
                'description' => 'Line', 'quantity' => 1, 'unit_price' => $n, 'line_total' => $n, 'tax_code' => $code,
                'tax_rate' => $r, 'tax_amount' => bcdiv(bcmul($n, $r, 4), '100', 2)]);
        }

        return $invoice->fresh('lines');
    }
}
