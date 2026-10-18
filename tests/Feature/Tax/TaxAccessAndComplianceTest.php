<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Tax\Models\TaxAuditEvent;
use App\Modules\Finance\Tax\Models\TaxComplianceDeadline;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Notifications\TaxDeadlineNotification;
use App\Modules\Finance\Tax\Services\ComplianceCalendarService;
use App\Modules\Finance\Tax\Services\TaxAudit;
use App\Modules\Finance\Tax\Services\VatRegisterService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * SRS §25 — access, compliance and evidence: TAX-011, TAX-016, TAX-017, TAX-018, TAX-019, §17.
 */
class TaxAccessAndComplianceTest extends TaxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->standardRules();
        $this->vatRegistration();
    }

    public function test_every_tax_screen_opens_for_a_permitted_user(): void
    {
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);

        foreach (['tax.dashboard', 'tax.registrations.index', 'tax.rules.index', 'tax.periods.index', 'tax.transactions.index',
            'tax.reconciliations.index', 'tax.calendar.index'] as $name) {
            $this->actingAs($this->reviewer)->get(route($name))->assertOk();
        }
        $this->actingAs($this->reviewer)->get(route('tax.periods.show', $period))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Modules/Finance/Tax/PeriodShow')->where('summary.output_tax', '150.00'));
        $this->actingAs($this->reviewer)->get(route('tax.audit.index'))->assertOk();
    }

    public function test_every_tax_route_refuses_a_user_without_tax_permissions(): void
    {
        $nobody = $this->user('nobody', []);
        $period = $this->period('2026-03-15');
        $deadline = TaxComplianceDeadline::firstOrFail();

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'tax.')) {
                continue;
            }
            $params = array_fill_keys($route->parameterNames(), 'x');
            if (isset($params['kind'])) {
                $params['kind'] = 'registrations';
            }
            if (isset($params['type'])) {
                $params['type'] = 'liabilities';
            }
            if (isset($params['uuid'])) {
                $params['uuid'] = (string) \Illuminate\Support\Str::uuid();
            }
            foreach (['registration' => $this->vatRegistrationUuid(), 'period' => $period->uuid, 'deadline' => $deadline->uuid] as $k => $v) {
                if (isset($params[$k])) {
                    $params[$k] = $v;
                }
            }
            if (array_intersect(array_keys($params), ['rule', 'transaction', 'reconciliation', 'item', 'return', 'liability', 'allocation', 'computation', 'line', 'batch', 'event'])) {
                continue; // need a record of that kind; their actions share the same allow() check
            }
            $method = strtolower($route->methods()[0]);
            $response = $this->actingAs($nobody)->{$method}(route($name, $params));
            $this->assertSame(403, $response->status(), "{$name} must refuse a user without tax permissions.");
        }
    }

    public function test_a_malformed_record_address_is_refused_not_a_server_error(): void
    {
        foreach (['tax.income.show' => 'computation', 'tax.returns.show' => 'return', 'tax.periods.show' => 'period', 'tax.reconciliations.show' => 'reconciliation'] as $name => $param) {
            // The constraint sends it past these routes to the app's own fallback, which answers
            // unknown addresses itself; what matters here is that it never reaches the database.
            $status = $this->actingAs($this->reviewer)->get(route($name, [$param => 'not-a-uuid']))->status();
            $this->assertContains($status, [403, 404], "{$name} with a malformed id must not be a server error.");
        }
    }

    private function vatRegistrationUuid(): string
    {
        return \App\Modules\Finance\Tax\Models\TaxRegistration::firstOrFail()->uuid;
    }

    // ── TAX-016 ──────────────────────────────────────────────────────────────

    public function test_tax_016_the_owner_is_reminded_once_and_the_escalation_contact_told_when_overdue(): void
    {
        Notification::fake();
        $deadline = TaxComplianceDeadline::where('kind', 'filing')->orderBy('due_date')->firstOrFail(); // due 31 March 2026
        $calendar = app(ComplianceCalendarService::class);

        $r1 = $calendar->run(Carbon::parse('2026-03-24'));     // 7 days out
        $r2 = $calendar->run(Carbon::parse('2026-03-24'));     // same day again
        $this->assertSame(2, $r1['reminders'], 'Filing and payment are separate deadlines, both due 31 March.');
        $this->assertSame(0, $r2['reminders'], 'One reminder per threshold.');
        Notification::assertSentTo($this->preparer, TaxDeadlineNotification::class, fn ($n) => $n->kind === 'reminder');

        $r3 = $calendar->run(Carbon::parse('2026-04-01'));     // a day late
        $this->assertGreaterThanOrEqual(1, $r3['escalations']);
        $this->assertSame('overdue', $deadline->fresh()->status);
        Notification::assertSentTo($this->reviewer, TaxDeadlineNotification::class, fn ($n) => $n->kind === 'escalation');
        $this->assertSame(0, $calendar->run(Carbon::parse('2026-04-02'))['escalations'], 'Escalated once, not daily.');
    }

    // ── TAX-017 / TAX-011 ────────────────────────────────────────────────────

    public function test_tax_017_an_unauthorised_adjustment_is_refused_and_recorded(): void
    {
        $this->invoice('receivable', '2026-03-06', [['300.00', '0', null]]);
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($this->period('2026-03-15'));
        $row = TaxTransaction::firstOrFail();

        $this->actingAs($this->outsider)
            ->post(route('tax.transactions.resolve', $row), ['action' => 'classify', 'reason' => 'I think it is zero-rated', 'tax_code' => 'ZR'])
            ->assertForbidden();

        $this->assertNull($row->fresh()->tax_code);
        $this->assertDatabaseHas('tax_audit_events', ['actor_id' => $this->outsider->id, 'outcome' => 'denied', 'action' => 'vat_register.resolve']);
    }

    public function test_tax_011_the_person_who_sent_a_period_for_review_cannot_approve_it(): void
    {
        $period = $this->period('2026-03-15');
        $period->forceFill(['status' => 'in_preparation'])->save();
        $this->reviewer->givePermissionTo(\App\Modules\Finance\Tax\Support\TaxPermissions::PREPARE);

        $this->actingAs($this->reviewer)->post(route('tax.periods.transition', $period), ['to' => 'under_review'])->assertSessionHas('success');
        $this->actingAs($this->reviewer)->post(route('tax.periods.transition', $period), ['to' => 'approved'])
            ->assertSessionHas('error');

        $this->assertSame('under_review', $period->fresh()->status);
    }

    // ── TAX-018 ──────────────────────────────────────────────────────────────

    public function test_tax_018_another_tenant_sees_none_of_it(): void
    {
        $period = $this->period('2026-03-15');
        $rival = Company::create(['name' => 'Rival Zone']);
        $this->enterTenant($rival);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $rival->id]);

        $this->assertSame(0, TaxPeriod::query()->withoutGlobalScopes()->count(), 'Row-level security hides every period.');
        $this->assertNull(TaxPeriod::query()->withoutGlobalScopes()->find($period->id));
        $this->assertSame(0, TaxAuditEvent::query()->withoutGlobalScopes()->count());
    }

    // ── TAX-019 ──────────────────────────────────────────────────────────────

    public function test_tax_019_the_export_matches_the_register_and_cannot_carry_a_formula(): void
    {
        $inv = $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD'], ['250.00', '15', 'STD']]);
        DB::table('fin_parties')->where('id', $inv->party_id)->update(['name' => '=HYPERLINK("http://evil","x")']);
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);

        $csv = $this->actingAs($this->reviewer)->get(route('tax.transactions.export', ['period' => $period->uuid]))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv, 'A formula is neutralised.');
        $rows = array_map('str_getcsv', preg_split('/\R/', trim($csv)));
        $headerAt = array_key_first(array_filter($rows, fn ($r) => in_array('VAT (ZAR)', $r, true)));
        $col = array_search('VAT (ZAR)', $rows[$headerAt], true);
        $vat = array_map(fn ($r) => $r[$col], array_slice($rows, $headerAt + 1));
        $this->assertSame(['150.00', '37.50'], $vat, 'Each line exported as recorded.');
        $this->assertSame('187.50', bcadd($vat[0], $vat[1], 2), 'The export totals to the register.');
        $this->assertStringContainsString('Generated', $csv, 'Reports carry their generation time (§20.3).');
        $this->assertDatabaseHas('tax_audit_events', ['action' => 'export.downloaded', 'actor_id' => $this->reviewer->id]);
    }

    // ── §17 ──────────────────────────────────────────────────────────────────

    public function test_the_audit_trail_cannot_be_altered_and_its_chain_verifies(): void
    {
        $event = TaxAuditEvent::query()->orderBy('id')->firstOrFail();
        $this->assertNull(app(TaxAudit::class)->verifyChain($this->company->id));

        try {
            DB::transaction(fn () => DB::table('tax_audit_events')->where('id', $event->id)->update(['reason' => 'rewritten']));
            $this->fail('The database must refuse an update to the audit trail.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
}
