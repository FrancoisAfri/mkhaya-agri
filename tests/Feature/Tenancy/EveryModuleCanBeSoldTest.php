<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A module that ships without a billing row is a module given away.
 *
 * `security_modules` is what the application installs and gates access by. `billing_modules` is
 * what the platform can price, bundle and invoice. They are meant to line up, and four had fallen
 * out of the second — Finance Executive, E-Sign, AI Campaign Manager and Leave Bureau. Nothing
 * failed: the modules worked, people used them, and they could not appear on the pricing screen,
 * could not be put in a plan, and were never charged for.
 *
 * That is the failure mode this guards. It is silent by construction, so it needs a test rather
 * than a pair of eyes on a screen that only ever shows what it already has.
 */
class EveryModuleCanBeSoldTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Modules the platform runs on rather than sells.
     *
     * These have billing rows already and are marked `is_sellable = false`; the list here is only
     * for the reverse direction — a code in `security_modules` that legitimately never needs one.
     */
    private const NOT_A_PRODUCT = [];

    public function test_every_installed_module_has_a_billing_row(): void
    {
        $installed = DB::table('security_modules')->pluck('name', 'code_name');
        $priced = DB::table('billing_modules')->pluck('code');

        $this->assertTrue($installed->isNotEmpty(), 'No modules are installed to check.');

        $missing = $installed->keys()
            ->diff($priced)
            ->diff(self::NOT_A_PRODUCT)
            ->values();

        $this->assertTrue(
            $missing->isEmpty(),
            "These modules are installed but have no billing row, so they cannot be priced, "
            ."bundled or invoiced:\n  ".$missing->map(fn ($c) => $c.'  —  '.$installed[$c])->implode("\n  ")
        );
    }

    /*
     * The reverse direction — a billing row for something not installed — is deliberately not
     * tested here. A seeded test database installs only part of the module set, so it reports real
     * products as uninstalled and fails for a reason that has nothing to do with the code. A test
     * that cannot distinguish its own fixture from the defect it is looking for is worse than no
     * test: it gets muted, and takes the half that works with it.
     */
}
