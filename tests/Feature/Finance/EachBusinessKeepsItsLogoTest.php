<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A business's logo, from the upload box to the invoice.
 *
 * Reported from Mkhaya Move: the whole letterhead saved — name, address, registration number —
 * and the logo box stayed empty. Three separate breaks, each enough on its own:
 *
 *  1. Uploads moved to the shared disk, which records the location in `logo_path`. That column was
 *     never added to the model's fillable list, so `fill()` dropped it while the same save set the
 *     old column to null. The image reached the disk and was immediately forgotten.
 *  2. The settings screen still sent only the old column, so even a recorded logo drew an empty
 *     box.
 *  3. The PDF renderer read the old column too, so a business with a logo printed without one.
 */
class EachBusinessKeepsItsLogoTest extends TestCase
{
    use DatabaseTransactions;

    /** A 1x1 red PNG. */
    private const UPLOAD = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $companyId;

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
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);

        Storage::fake('documents');

        $this->user = User::first() ?? User::factory()->create();
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function business(bool $default): FinEntity
    {
        // A tenant may hold only one default, so the existing one is reused rather than rejected.
        if ($default) {
            $existing = FinEntity::withoutGlobalScopes()
                ->where('company_id', $this->companyId)->where('is_default', true)->first();

            if ($existing) {
                return $existing;
            }
        }

        return FinEntity::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'name'       => ($default ? 'MK ' : 'Move ').Str::random(4),
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

    private function openSettings(): array
    {
        return $this->acting()->get(route('finance.accounting.settings'))
            ->assertOk()->viewData('page')['props']['settings'];
    }

    public function test_an_uploaded_logo_comes_back_when_the_page_is_reopened(): void
    {
        $move = $this->business(false);
        app(CurrentEntity::class)->set($move->id);

        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
            'logo'         => self::UPLOAD,
        ])->assertSessionHasNoErrors();

        $saved = FinSetting::query()->where('entity_id', $move->id)->first();

        $this->assertNotNull($saved, 'The settings row was never written.');
        $this->assertNotNull(
            $saved->logo_path,
            'The logo reached the disk and its location was dropped on the way to the row.'
        );
        Storage::disk('documents')->assertExists($saved->logo_path);

        $this->assertNotNull(
            $this->openSettings()['logo'] ?? null,
            'The page drew an empty box over a logo that is sitting on the disk.'
        );
    }

    public function test_saving_the_page_again_does_not_lose_the_logo(): void
    {
        $move = $this->business(false);
        app(CurrentEntity::class)->set($move->id);

        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
            'logo'         => self::UPLOAD,
        ]);

        $storedAt = FinSetting::query()->where('entity_id', $move->id)->first()->logo_path;

        // Reopening the page and changing the phone number posts the logo back as the URL it was
        // handed. That is neither a new image nor a removal, and must not disturb either column.
        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
            'phone'        => '+27 10 534 8787',
            'logo'         => $this->openSettings()['logo'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            $storedAt,
            FinSetting::query()->where('entity_id', $move->id)->first()->logo_path,
            'Saving the page without touching the logo moved or lost it.'
        );
    }

    public function test_remove_still_removes(): void
    {
        $move = $this->business(false);
        app(CurrentEntity::class)->set($move->id);

        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
            'logo'         => self::UPLOAD,
        ]);

        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
            'logo'         => null,
        ])->assertSessionHasNoErrors();

        $after = FinSetting::query()->where('entity_id', $move->id)->first();

        $this->assertNull($after->logo_path, 'Pressing Remove left the logo in place.');
        $this->assertNull($this->openSettings()['logo'] ?? null);
    }

    public function test_the_invoice_prints_the_logo_rather_than_a_link_to_it(): void
    {
        $move = $this->business(false);
        app(CurrentEntity::class)->set($move->id);

        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
            'logo'         => self::UPLOAD,
        ]);

        $logo = app(FinanceDocumentService::class)->company()['logo'];

        $this->assertNotNull($logo, 'The document renderer could not find the business logo.');

        // A URL here would send the renderer back through this application's auth mid-PDF, which
        // fails quietly and prints an unbranded invoice.
        $this->assertStringStartsWith('data:image/', $logo);
    }

    public function test_two_businesses_do_not_share_one_logo(): void
    {
        $mk   = $this->business(true);
        $move = $this->business(false);

        app(CurrentEntity::class)->set($mk->id);
        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MK',
            'logo'         => self::UPLOAD,
        ]);
        $mkLogo = FinSetting::query()->where('entity_id', $mk->id)->first()?->logo_path;

        app(CurrentEntity::class)->set($move->id);
        $this->acting()->post(route('finance.accounting.settings.save'), [
            'company_name' => 'MKHAYA MOVE (PTY) LTD',
            'logo'         => self::UPLOAD,
        ]);
        $moveLogo = FinSetting::query()->where('entity_id', $move->id)->first()?->logo_path;

        $this->assertNotNull($mkLogo);
        $this->assertNotNull($moveLogo);
        $this->assertNotSame($mkLogo, $moveLogo, 'Both businesses point at the same logo file.');
    }
}
