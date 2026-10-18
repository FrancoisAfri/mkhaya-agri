<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "When i click on letterhead and banking of mkhaya mk the changes also affect mkhaya move."
 *
 * The Businesses screen gives every business a menu, and "Letterhead" in it was a plain link to
 * the settings screen. That screen shows whichever business is being worked in — so opening
 * Mkhaya Move's letterhead from Move's own card edited Mkhaya MK's, under a heading that said
 * Move. Both cards led to one row and whichever was saved last won.
 *
 * Banking is the same screen and the same row, which is why it moved with it.
 *
 * The menu now switches to the business it belongs to before opening its letterhead, and the
 * screen says whose letterhead it is.
 */
class TheCardsMenuIsAboutThatCardTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private FinEntity $mk;

    private FinEntity $move;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        FinSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->delete();

        $this->mk = FinEntity::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('is_default', true)->first()
            ?? $this->business('Mkhaya MK', true);

        $this->move = $this->business('Mkhaya Move', false);

        $this->user = User::first() ?? User::factory()->create();
        $this->user->forceFill(['fin_entity_id' => null])->saveQuietly();
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function business(string $name, bool $default): FinEntity
    {
        return FinEntity::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'name'       => $name.' '.Str::random(4),
            'status'     => 'active',
            'is_default' => $default,
        ]);
    }

    private function acting(): self
    {
        return $this->actingAs($this->user)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ]);
    }

    /** What the card's Letterhead item now does. */
    private function openLetterheadOf(FinEntity $business)
    {
        return $this->acting()->post(
            route('finance.accounting.entities.switch', ['entity' => $business->id]),
            ['to' => 'finance.accounting.settings']
        );
    }

    public function test_opening_a_businesss_letterhead_lands_on_that_business(): void
    {
        app(CurrentEntity::class)->set($this->mk->id);

        $this->openLetterheadOf($this->move)
            ->assertRedirect(route('finance.accounting.settings'));

        $this->assertSame(
            $this->move->id,
            app(CurrentEntity::class)->id(),
            'The letterhead screen would have opened on a different business from the card clicked.'
        );
    }

    public function test_the_screen_says_whose_letterhead_it_is(): void
    {
        app(CurrentEntity::class)->set($this->move->id);

        $props = $this->acting()->get(route('finance.accounting.settings'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame($this->move->name, $props['business'] ?? null);
    }

    public function test_saving_one_businesss_letterhead_leaves_the_others_alone(): void
    {
        // Exactly the reported sequence: open one card's letterhead, save, then the other's.
        $this->openLetterheadOf($this->move);
        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
            'bank_name'    => 'FNB',
            'bank_account_number' => '111111111',
        ])->assertSessionHasNoErrors();

        $this->openLetterheadOf($this->mk);
        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MK (PTY) LTD',
            'bank_name'    => 'Standard Bank',
            'bank_account_number' => '222222222',
        ])->assertSessionHasNoErrors();

        $moveRow = FinSetting::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('entity_id', $this->move->id)->first();
        $mkRow = FinSetting::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('entity_id', $this->mk->id)->first();

        $this->assertNotNull($moveRow, 'Mkhaya Move has no letterhead of its own.');
        $this->assertNotNull($mkRow, 'Mkhaya MK has no letterhead of its own.');

        $this->assertSame('MKHAYA MOVE (PTY) LTD', $moveRow->company_name);
        $this->assertSame('FNB', $moveRow->bank_name);
        $this->assertSame('111111111', $moveRow->bank_account_number);

        $this->assertSame('MKHAYA MK (PTY) LTD', $mkRow->company_name);
        $this->assertSame('Standard Bank', $mkRow->bank_name);
        $this->assertSame('222222222', $mkRow->bank_account_number);
    }

    public function test_the_landing_place_cannot_be_pointed_off_the_module(): void
    {
        // A redirect target taken from the request is the usual way an open redirect gets in.
        foreach (['https://example.com/', 'login', 'tenancy.subscription.index'] as $target) {
            $this->acting()
                ->post(route('finance.accounting.entities.switch', ['entity' => $this->move->id]), ['to' => $target])
                ->assertRedirect();

            $this->assertNotEquals(
                'https://example.com/',
                $this->acting()->post(
                    route('finance.accounting.entities.switch', ['entity' => $this->move->id]),
                    ['to' => $target]
                )->headers->get('Location')
            );
        }
    }

    public function test_switching_without_a_destination_still_just_switches(): void
    {
        app(CurrentEntity::class)->set($this->mk->id);

        $this->acting()->post(route('finance.accounting.entities.switch', ['entity' => $this->move->id]));

        $this->assertSame($this->move->id, app(CurrentEntity::class)->id());
    }

    public function test_the_card_shows_the_letterhead_a_business_actually_prints(): void
    {
        // The default business reads the row the tenant had before businesses existed, so a card
        // that looks only for its own row calls a live letterhead missing.
        FinSetting::withoutGlobalScopes()->create([
            'company_id'   => $this->companyId,
            'entity_id'    => null,
            'company_name' => 'THE OLD LETTERHEAD',
        ]);

        $entities = collect($this->acting()->get(route('finance.accounting.entities'))
            ->assertOk()->viewData('page')['props']['entities']);

        $this->assertTrue(
            $entities->firstWhere('id', $this->mk->id)['configured'],
            'The default business is shown as having no letterhead while it has one.'
        );

        // And the second business genuinely has none, which must still be said.
        $this->assertFalse($entities->firstWhere('id', $this->move->id)['configured']);
    }

    public function test_a_business_without_a_vat_number_is_not_called_unconfigured(): void
    {
        app(CurrentEntity::class)->set($this->move->id);

        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
        ])->assertSessionHasNoErrors();

        $entities = collect($this->acting()->get(route('finance.accounting.entities'))
            ->assertOk()->viewData('page')['props']['entities']);

        // Not every business is registered for VAT, and not having a number is not the same as
        // having no letterhead.
        $this->assertTrue($entities->firstWhere('id', $this->move->id)['configured']);
    }
}
