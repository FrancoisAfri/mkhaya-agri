<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Tax\Services\TaxReconciliationService;
use App\Modules\Finance\Tax\Services\VatRegisterService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use App\Modules\Finance\Tax\Support\TaxSettings;

/** §3.1 Tax Administration: a company's own settings, audited, applied where they are used. */
class TaxAdminTest extends TaxTestCase
{
    public function test_a_companys_tolerance_applies_to_its_reconciliations_and_the_change_is_audited(): void
    {
        $admin = $this->user('admin', [TaxPermissions::VIEW, TaxPermissions::ADMIN]);
        $this->standardRules();
        $this->vatRegistration();

        $this->actingAs($admin)->post(route('tax.admin.update'), ['reconciliation_tolerance' => '5.00', 'reason' => 'Agreed with the CFO'])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('tax_audit_events', ['action' => 'settings.updated', 'reason' => 'Agreed with the CFO']);
        $this->assertSame('5.00', (string) app(TaxSettings::class)->get('reconciliation_tolerance', $this->company->id));

        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);
        $this->assertSame('5.00', (string) app(TaxReconciliationService::class)->runVatToLedger($period->fresh())->tolerance);

        $this->actingAs($admin)->post(route('tax.admin.update'), ['reconciliation_tolerance' => '9.00', 'reason' => ''])->assertSessionHasErrors('reason');
    }

    public function test_the_screen_shows_who_holds_tax_permissions_and_is_for_administrators(): void
    {
        $admin = $this->user('admin', [TaxPermissions::VIEW, TaxPermissions::ADMIN]);
        $both = $this->user('both', [TaxPermissions::VIEW, TaxPermissions::PREPARE, TaxPermissions::APPROVE]);

        $this->actingAs($admin)->get(route('tax.admin.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Finance/Tax/Admin')->has('settings', count(TaxSettings::KEYS))
                ->where('users', fn ($users) => collect($users)->contains(fn ($u) => $u['id'] === $both->id && in_array('tax.approve', $u['permissions'], true))));
        $this->actingAs($this->preparer)->get(route('tax.admin.index'))->assertForbidden();
    }
}
